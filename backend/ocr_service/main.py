"""
EMPOWER OCR service.

A small FastAPI application that reads applicant documents and returns the text
it found, together with a best guess at the individual fields. It exists so HR do
not have to retype a résumé that the applicant has already handed over on paper.

What it deliberately does not do is write to any applicant record. It returns a
proposal; Laravel presents that proposal to an HR officer, who corrects it and
decides whether to apply it. That mirrors how the rest of EMPOWER works — the
competency engine recommends candidates and a person decides — and it matters
here because OCR on a photographed document is never certain.
"""

from __future__ import annotations

import io
import logging
import os
import time
from contextlib import asynccontextmanager

import cv2
import numpy as np
from fastapi import Depends, FastAPI, File, Header, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse

import extraction
import preprocessing

logging.basicConfig(level=logging.INFO, format="%(asctime)s  %(levelname)-7s %(message)s")
logger = logging.getLogger("empower.ocr")

MAX_UPLOAD_BYTES = 10 * 1024 * 1024
MAX_PDF_PAGES = 5  # résumés run to two or three; beyond that it is not a résumé
MAX_IMAGE_PIXELS = 49_000_000

ACCEPTED_TYPES = {
    "image/jpeg", "image/jpg", "image/png", "image/webp", "application/pdf",
}

# Set OCR_SERVICE_TOKEN in both this service and Laravel to require a shared
# secret. Left unset the service is open, which is acceptable only when it is not
# reachable from outside the host.
SERVICE_TOKEN = os.environ.get("OCR_SERVICE_TOKEN", "").strip()

# Swap the recognition models for their mobile variants: set OCR_FAST_MODELS=1.
#
# Roughly three times quicker — about 12 seconds a page against 40 on the
# development machine — but it does cost accuracy. Measured on the sample
# resume, the mobile detector invented a leading character ("eCivil Status")
# and the mobile recogniser read the certification "NC II" as "NC Il".
#
# Off by default because these values go onto a person's employment record, and
# a name or a certification silently corrupted to save half a minute is a bad
# trade for an agency that has to stand behind the file. Worth turning on if
# scanning is being used for rough triage rather than for the record itself.
FAST_MODELS = os.environ.get("OCR_FAST_MODELS", "").strip().lower() in {"1", "true", "yes"}

_engine = None


def get_engine():
    """
    Build the PaddleOCR pipeline on first use.

    Loading is deferred rather than done at import so the service starts, and
    answers /health, immediately. The first request pays a few seconds more for
    the load; the recognition itself is slow either way, because oneDNN has to
    stay disabled (see enable_mkldnn below) and the reference CPU kernels take
    roughly forty seconds a page on a development laptop.
    """
    global _engine

    if _engine is not None:
        return _engine

    from paddleocr import PaddleOCR

    logger.info("Loading PaddleOCR models (first request only)…")
    started = time.perf_counter()

    options = dict(
        lang="en",
        # Handles a page photographed sideways or upside down. preprocessing.py
        # deliberately caps its own deskew at ±15° and leaves quarter-turns to
        # this classifier, which is far more reliable at them, so the two are
        # complementary rather than duplicated work.
        use_doc_orientation_classify=True,
        use_textline_orientation=True,
        # Unwarping is off.
        #
        # UVDoc flattens curved pages — a book held open, a page lifted off the
        # desk. The agency photographs flat documents and laminated IDs on a
        # counter, so it corrects a distortion that is not there. Measured on
        # the sample resume it changed not a single recognised character while
        # adding several seconds to every scan, so it is cost without benefit
        # here. Turn it on if intake ever starts accepting photographs of bound
        # documents.
        use_doc_unwarping=False,
        # Required, not an optimisation. PaddlePaddle 3.x's oneDNN backend cannot
        # translate one of the text-detection model's attributes under the new
        # PIR executor, and every recognition call dies with
        #   NotImplementedError: ConvertPirAttribute2RuntimeAttribute not support
        #   [pir::ArrayAttribute<pir::DoubleAttribute>]
        # Disabling oneDNN falls back to the reference CPU kernels, which are
        # slower per page but actually work. Setting FLAGS_use_mkldnn=0 in the
        # environment does *not* help — it has to be passed here.
        enable_mkldnn=False,
    )

    # Optional speed/accuracy trade-off, off by default. See FAST_MODELS.
    if FAST_MODELS:
        options.update(
            text_detection_model_name="PP-OCRv5_mobile_det",
            text_recognition_model_name="PP-OCRv5_mobile_rec",
        )

    _engine = PaddleOCR(**options)

    logger.info(
        "Models loaded in %.1fs (%s)",
        time.perf_counter() - started,
        "fast/mobile" if FAST_MODELS else "accurate/default",
    )
    return _engine


@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("EMPOWER OCR service starting")
    if SERVICE_TOKEN:
        logger.info("Shared-secret authentication is enabled")
    else:
        logger.warning(
            "OCR_SERVICE_TOKEN is not set — this service will accept any caller. "
            "Set it in production."
        )
    yield
    logger.info("EMPOWER OCR service stopping")


app = FastAPI(
    title="EMPOWER OCR Service",
    description="Reads applicant documents for CDE Manpower Services.",
    version="2.0.0",
    lifespan=lifespan,
)


def verify_token(x_ocr_token: str | None = Header(default=None)) -> None:
    """Reject callers without the shared secret, when one is configured."""
    if not SERVICE_TOKEN:
        return

    if x_ocr_token != SERVICE_TOKEN:
        raise HTTPException(status_code=401, detail="Invalid or missing service token.")


@app.get("/health")
def health() -> dict:
    """
    Liveness probe.

    Reports whether the models are resident so a slow first request can be
    explained rather than mistaken for a hang.
    """
    return {
        "status": "ok",
        "service": "empower-ocr",
        "version": "2.0.0",
        "models_loaded": _engine is not None,
        "authentication": "enabled" if SERVICE_TOKEN else "open",
    }


@app.post("/ocr/parse", dependencies=[Depends(verify_token)])
async def parse(
    request: Request,
    file: UploadFile = File(...),
    document_type: str = "auto",
) -> JSONResponse:
    """
    Read a document and return its text plus proposed applicant fields.
    """
    started = time.perf_counter()

    content_length = request.headers.get("content-length")
    if content_length and int(content_length) > MAX_UPLOAD_BYTES + 1024 * 1024:
        raise HTTPException(status_code=413, detail="The upload is larger than the allowed limit.")

    chunks: list[bytes] = []
    total = 0
    while chunk := await file.read(1024 * 1024):
        total += len(chunk)
        if total > MAX_UPLOAD_BYTES:
            raise HTTPException(
                status_code=413,
                detail=f"The file exceeds the {MAX_UPLOAD_BYTES / 1048576:.0f} MB limit.",
            )
        chunks.append(chunk)

    contents = b"".join(chunks)
    _validate_upload(file, contents)

    try:
        pages = _load_pages(contents, file.content_type or "")
    except Exception as error:  # noqa: BLE001 - surfaced to the caller as 422
        logger.warning("Could not decode upload %s: %s", file.filename, error)
        raise HTTPException(
            status_code=422,
            detail=(
                "The file could not be opened as an image or PDF. "
                "Try re-saving it, or photograph the document again."
            ),
        ) from error

    engine = get_engine()

    all_lines: list[str] = []
    confidences: list[float] = []
    page_notes: list[dict] = []

    for index, page in enumerate(pages):
        prepared, notes = preprocessing.prepare(page)

        # ID cards are usually photographed against a contrasting surface, so
        # cropping to the card removes background text and improves framing.
        # Résumés are full-page scans where cropping would risk losing content.
        if document_type in {"id", "umid", "philsys", "drivers_license"}:
            prepared = preprocessing.crop_document(prepared)
            notes["cropped_to_document"] = True

        lines, page_confidences = _recognise(engine, prepared)

        all_lines.extend(lines)
        confidences.extend(page_confidences)
        page_notes.append({"page": index + 1, **notes, "lines_found": len(lines)})

    if not all_lines:
        return JSONResponse(
            status_code=200,
            content={
                "success": False,
                "message": (
                    "No readable text was found. The image may be too dark, too "
                    "blurred, or too low in resolution."
                ),
                "text": "",
                "lines": [],
                "pages": page_notes,
                "extraction": None,
            },
        )

    result = extraction.extract(all_lines, document_type)
    mean_confidence = sum(confidences) / len(confidences) if confidences else 0.0

    logger.info(
        "Read %s: %d lines, mean confidence %.2f, %.1fs",
        file.filename,
        len(all_lines),
        mean_confidence,
        time.perf_counter() - started,
    )

    return JSONResponse(
        content={
            "success": True,
            "message": "Document read successfully.",
            "text": "\n".join(all_lines),
            "lines": all_lines,
            "recognition_confidence": round(mean_confidence, 3),
            "pages": page_notes,
            "processing_seconds": round(time.perf_counter() - started, 2),
            "extraction": result,
            # Restated in the payload so a client cannot treat this as
            # authoritative by accident.
            "notice": "Proposed values only. They must be reviewed before being saved.",
        }
    )


def _validate_upload(file: UploadFile, contents: bytes) -> None:
    if not contents:
        raise HTTPException(status_code=422, detail="The uploaded file is empty.")

    if len(contents) > MAX_UPLOAD_BYTES:
        raise HTTPException(
            status_code=413,
            detail=f"File is {len(contents) / 1048576:.1f} MB. The limit is "
            f"{MAX_UPLOAD_BYTES / 1048576:.0f} MB.",
        )

    if file.content_type and file.content_type not in ACCEPTED_TYPES:
        raise HTTPException(
            status_code=415,
            detail=f"Unsupported file type '{file.content_type}'. "
            "Send a PDF or an image.",
        )


def _load_pages(contents: bytes, content_type: str) -> list[np.ndarray]:
    """
    Decode the upload into one or more page images.

    PDF support matters more than it might appear: applicants email résumés as
    PDFs, and the previous version of this service silently failed on them.
    """
    if content_type == "application/pdf" or contents[:5] == b"%PDF-":
        return _render_pdf(contents)

    image = cv2.imdecode(np.frombuffer(contents, np.uint8), cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("not a decodable image")

    if image.shape[0] * image.shape[1] > MAX_IMAGE_PIXELS:
        raise ValueError("image dimensions are too large")

    return [image]


def _render_pdf(contents: bytes) -> list[np.ndarray]:
    """
    Rasterise a PDF with pypdfium2.

    Chosen over pdf2image because it needs no external Poppler binary, which
    keeps both the Windows development setup and the deployment image simple.
    Rendered at roughly 200 DPI, enough for body text without producing images
    so large they slow recognition down.
    """
    import pypdfium2 as pdfium

    document = pdfium.PdfDocument(io.BytesIO(contents))
    if len(document) > MAX_PDF_PAGES:
        raise ValueError(f"PDF has {len(document)} pages; the limit is {MAX_PDF_PAGES}")

    page_count = len(document)

    pages: list[np.ndarray] = []
    for index in range(page_count):
        page = document[index]
        page_width, page_height = page.get_size()
        if page_width * 200 / 72 * page_height * 200 / 72 > MAX_IMAGE_PIXELS:
            raise ValueError("PDF page dimensions are too large")
        bitmap = page.render(scale=200 / 72)
        image = np.array(bitmap.to_pil().convert("RGB"))
        if image.shape[0] * image.shape[1] > MAX_IMAGE_PIXELS:
            raise ValueError("PDF page dimensions are too large")
        pages.append(cv2.cvtColor(image, cv2.COLOR_RGB2BGR))

    return pages


def _recognise(engine, image: np.ndarray) -> tuple[list[str], list[float]]:
    """
    Run recognition and flatten the result.

    PaddleOCR 3.x returns a list of result objects keyed by 'rec_texts' and
    'rec_scores'. This differs from the 2.x nested-list shape, which is why the
    previous version of this service raised a TypeError against a current
    install; both shapes are handled so a version bump does not break the
    service outright.
    """
    raw = engine.predict(image)

    lines: list[str] = []
    scores: list[float] = []

    for result in raw or []:
        # 3.x: a dict-like result carrying parallel text and score arrays.
        if hasattr(result, "get") or isinstance(result, dict):
            texts = result.get("rec_texts") or []
            confidences = result.get("rec_scores") or []
            for text, score in zip(texts, confidences):
                cleaned = str(text).strip()
                if cleaned:
                    lines.append(cleaned)
                    scores.append(float(score))
            continue

        # 2.x fallback: [[box, (text, score)], …]
        for entry in result or []:
            try:
                text, score = entry[1]
                cleaned = str(text).strip()
                if cleaned:
                    lines.append(cleaned)
                    scores.append(float(score))
            except (IndexError, TypeError, ValueError):
                continue

    return lines, scores


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        app,
        host=os.environ.get("OCR_HOST", "127.0.0.1"),
        port=int(os.environ.get("OCR_PORT", "8001")),
    )
