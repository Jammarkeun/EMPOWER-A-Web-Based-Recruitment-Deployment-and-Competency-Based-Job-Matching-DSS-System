# EMPOWER OCR Service

Reads applicant documents so HR do not have to retype a résumé the applicant has
already handed over on paper.

## What it does, and what it deliberately does not

It returns **proposed** values. It never writes to an applicant record.

Recognition on a photographed document is never certain, and a misread birth date
written silently into someone's file is worse than an empty field. Laravel shows
the proposal to an HR officer, who corrects it and decides whether to save it —
the same principle as the competency engine, which ranks candidates but never
hires one.

## Running it

```bash
cd backend/ocr_service
pip install -r requirements.txt

# Must match OCR_SERVICE_TOKEN in the Laravel .env
set OCR_SERVICE_TOKEN=empower-dev-token

python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

The first request loads the recognition models and takes roughly 30 seconds.
Every request after that takes a few seconds. `GET /health` reports whether the
models are resident, so a slow first call can be explained rather than mistaken
for a hang.

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Liveness, and whether models are loaded |
| `POST` | `/ocr/parse` | Read a document; returns text and proposed fields |

`/ocr/parse` accepts a multipart `file` (PDF, JPEG, PNG, WebP, BMP, TIFF) and an
optional `document_type` of `auto`, `resume`, `id`, `umid`, `philsys`, or
`drivers_license`.

Authentication is a shared secret in the `X-OCR-Token` header, enabled whenever
`OCR_SERVICE_TOKEN` is set.

## Two things worth knowing before changing this code

**1. `enable_mkldnn=False` is required, not a tuning choice.**

PaddlePaddle 3.x's oneDNN backend cannot translate one of the text-detection
model's attributes under the new PIR executor. Every recognition call fails with:

```
NotImplementedError: ConvertPirAttribute2RuntimeAttribute not support
[pir::ArrayAttribute<pir::DoubleAttribute>]
```

Disabling oneDNN falls back to the reference CPU kernels — slower per page, but
they work. Setting `FLAGS_use_mkldnn=0` in the environment does *not* help; it
has to be passed to the `PaddleOCR` constructor.

**2. The preprocessing deliberately does less than you might expect.**

PaddleOCR 3.x already performs document orientation classification and unwarping
with dedicated models, and its recogniser was trained on natural photographs.
Binarising a document — the habit carried over from older Tesseract pipelines —
destroys the anti-aliased letter edges the model relies on and measurably *lowers*
accuracy. `preprocessing.py` therefore only does what PaddleOCR does not: shadow
removal, small-angle deskew, and rescaling.

## Accuracy, honestly

Values with a fixed shape are extracted reliably, because the pattern is the
evidence:

| Field | Typical confidence |
| --- | --- |
| Email address | 0.95 |
| Mobile number | 0.90 |
| Date of birth (labelled) | 0.85 |
| SSS / PhilHealth / Pag-IBIG | 0.85 |
| Sex, civil status | 0.80 |

Names and addresses cannot be. Résumé layouts vary endlessly and any rule that
appears to work on a handful of samples fails on the next one, so these score
0.45–0.60 and are flagged for review in the interface. That flagging is the
feature, not an apology for it.

## If it will not start

- **`ModuleNotFoundError: paddle`** — `paddlepaddle` is the inference engine and
  is separate from `paddleocr`. Install both.
- **Models re-download on every start** — they cache to `~/.paddlex`. Check the
  process can write there.
- **Laravel reports the service unavailable** — confirm it is listening on the
  port in `OCR_SERVICE_URL`, and that `OCR_SERVICE_TOKEN` matches on both sides.

## If it has to be dropped

Nothing else depends on this service. `config/ocr.php` has an `enabled` flag, and
with it off the applicant form behaves exactly as it did before — HR type the
details in, as they do today. This was flagged to CDE Manpower Services in the
project timeline as the component most likely to be descoped.
