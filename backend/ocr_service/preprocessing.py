"""
Image preparation before OCR.

The guiding rule here is to do less than the obvious thing. PaddleOCR 3.x already
performs document orientation classification and unwarping with dedicated models,
and its recognition network was trained on natural photographs. Aggressive
"cleanup" therefore tends to *reduce* accuracy rather than improve it — binarising
a document photo, in particular, destroys the anti-aliased letter edges the model
relies on, which is a common and counter-productive habit carried over from older
Tesseract pipelines.

So this module sticks to corrections PaddleOCR does not make for itself:

  * shadow and uneven lighting removal, common in phone photographs of documents
  * small-angle deskew, which helps line segmentation
  * upscaling of low-resolution captures, since text below roughly 20 px tall is
    where recognition falls apart
"""

from __future__ import annotations

import cv2
import numpy as np

# Below this height, characters are usually too small for reliable recognition.
MIN_HEIGHT_FOR_OCR = 1000

# Above this, we are paying for pixels the model does not use.
MAX_DIMENSION = 2600


def prepare(image: np.ndarray) -> tuple[np.ndarray, dict]:
    """
    Return a cleaned copy of the image plus notes on what was done.

    The notes are surfaced in the API response so that a poor result can be
    explained — "the scan was very low resolution" is more useful to an HR
    officer than a silently bad extraction.
    """
    notes: dict = {}
    original_height, original_width = image.shape[:2]
    notes["original_size"] = f"{original_width}x{original_height}"

    image = _remove_shadows(image)
    notes["shadow_correction"] = True

    image, angle = _deskew(image)
    notes["deskew_angle"] = round(angle, 2)

    image, scale = _rescale(image)
    notes["scale_factor"] = round(scale, 2)

    height, width = image.shape[:2]
    notes["processed_size"] = f"{width}x{height}"

    # Flagged rather than silently corrected: no amount of upscaling recovers
    # detail that was never captured, and the user should be told to rephotograph.
    notes["low_resolution_warning"] = original_height < 700

    return image, notes


def _remove_shadows(image: np.ndarray) -> np.ndarray:
    """
    Flatten uneven lighting while keeping the image in greyscale-on-colour form.

    Works per channel: dilating and median-blurring produces an estimate of the
    local background, and dividing the original by that background cancels
    gradients from a desk lamp or a phone's own shadow. Unlike thresholding, this
    keeps the smooth edges the recogniser expects.
    """
    channels = cv2.split(image)
    corrected = []

    for channel in channels:
        background = cv2.dilate(channel, np.ones((7, 7), np.uint8))
        background = cv2.medianBlur(background, 21)

        difference = 255 - cv2.absdiff(channel, background)
        normalised = cv2.normalize(
            difference, None, alpha=0, beta=255, norm_type=cv2.NORM_MINMAX
        )
        corrected.append(normalised)

    return cv2.merge(corrected)


def _deskew(image: np.ndarray, limit: float = 15.0) -> tuple[np.ndarray, float]:
    """
    Correct small rotations, of the kind produced by a document laid down
    slightly crooked.

    Deliberately capped at ±15°. Larger rotations are usually a page turned 90°
    or 180°, which PaddleOCR's own orientation classifier handles far more
    reliably than angle estimation from pixel moments — attempting it here would
    fight that model rather than help it.
    """
    grey = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

    # Invert so text is bright, then threshold only to find the text mass. The
    # threshold is used for angle estimation alone and never fed to the OCR.
    inverted = cv2.bitwise_not(grey)
    _, mask = cv2.threshold(inverted, 0, 255, cv2.THRESH_BINARY | cv2.THRESH_OTSU)

    coords = cv2.findNonZero(mask)
    if coords is None:
        return image, 0.0

    angle = cv2.minAreaRect(coords)[-1]

    # minAreaRect reports within [0, 90); map to a signed small rotation.
    if angle > 45:
        angle -= 90

    if abs(angle) < 0.3 or abs(angle) > limit:
        return image, 0.0

    height, width = image.shape[:2]
    centre = (width // 2, height // 2)
    matrix = cv2.getRotationMatrix2D(centre, angle, 1.0)

    rotated = cv2.warpAffine(
        image,
        matrix,
        (width, height),
        flags=cv2.INTER_CUBIC,
        borderMode=cv2.BORDER_REPLICATE,
    )

    return rotated, angle


def _rescale(image: np.ndarray) -> tuple[np.ndarray, float]:
    """
    Bring the image into the resolution band where recognition performs best.

    Small images are enlarged with cubic interpolation, which does not invent
    detail but does give the detector more pixels to place boundaries on. Large
    images are reduced with area interpolation, the better choice for
    downsampling.
    """
    height, width = image.shape[:2]

    if height < MIN_HEIGHT_FOR_OCR:
        scale = MIN_HEIGHT_FOR_OCR / height
        scale = min(scale, 3.0)  # beyond this it is only blur, magnified
        return (
            cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC),
            scale,
        )

    largest = max(height, width)
    if largest > MAX_DIMENSION:
        scale = MAX_DIMENSION / largest
        return (
            cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA),
            scale,
        )

    return image, 1.0


def crop_document(image: np.ndarray) -> np.ndarray:
    """
    Detect a document lying on a contrasting surface and correct its perspective.

    Applied to ID cards, where the card occupies part of the frame at an angle.
    If four clean corners cannot be found the original is returned unchanged —
    a wrong crop loses text outright, which is far worse than a slightly skewed
    but complete image.
    """
    height, width = image.shape[:2]
    grey = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blurred = cv2.GaussianBlur(grey, (5, 5), 0)
    edges = cv2.Canny(blurred, 50, 150)
    edges = cv2.dilate(edges, np.ones((3, 3), np.uint8), iterations=1)

    contours, _ = cv2.findContours(edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        return image

    largest = max(contours, key=cv2.contourArea)

    # Ignore anything that is not clearly the dominant object in the frame.
    if cv2.contourArea(largest) < 0.25 * height * width:
        return image

    perimeter = cv2.arcLength(largest, True)
    approximation = cv2.approxPolyDP(largest, 0.02 * perimeter, True)

    if len(approximation) != 4:
        return image

    return _four_point_transform(image, approximation.reshape(4, 2).astype("float32"))


def _four_point_transform(image: np.ndarray, points: np.ndarray) -> np.ndarray:
    """Flatten a quadrilateral into a rectangle."""
    ordered = _order_corners(points)
    (top_left, top_right, bottom_right, bottom_left) = ordered

    width = int(
        max(np.linalg.norm(bottom_right - bottom_left), np.linalg.norm(top_right - top_left))
    )
    height = int(
        max(np.linalg.norm(top_right - bottom_right), np.linalg.norm(top_left - bottom_left))
    )

    if width < 50 or height < 50:
        return image

    destination = np.array(
        [[0, 0], [width - 1, 0], [width - 1, height - 1], [0, height - 1]],
        dtype="float32",
    )

    matrix = cv2.getPerspectiveTransform(ordered, destination)
    return cv2.warpPerspective(image, matrix, (width, height))


def _order_corners(points: np.ndarray) -> np.ndarray:
    """Order four corners as top-left, top-right, bottom-right, bottom-left."""
    ordered = np.zeros((4, 2), dtype="float32")

    total = points.sum(axis=1)
    ordered[0] = points[np.argmin(total)]  # smallest x+y is top-left
    ordered[2] = points[np.argmax(total)]  # largest is bottom-right

    difference = np.diff(points, axis=1)
    ordered[1] = points[np.argmin(difference)]  # smallest y-x is top-right
    ordered[3] = points[np.argmax(difference)]

    return ordered
