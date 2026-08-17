"""
Derives the application's logo assets from the artwork the agency supplied.

Run from the repository root:

    .venv/Scripts/python.exe docs/08-brand/generate-logo.py

Produces, into both frontend/public/logo/ and backend/laravel/public/logo/:

    cde-manpower.png       512x512  full lockup, for large placements
    cde-manpower-mark.png  256x256  octagon only, for small placements

The boundaries between the octagon and the wordmark are measured from the image
rather than hard-coded, so re-running against corrected artwork of the same
layout needs no edits here. The values found are printed; check them if the new
artwork is laid out differently.
"""

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
SOURCE = Path(__file__).parent / 'cde-manpower-original.png'

TARGETS = [
    ROOT / 'frontend' / 'public' / 'logo',
    ROOT / 'backend' / 'laravel' / 'public' / 'logo',
]

FULL_PX = 512
MARK_PX = 256

# Breathing room around the artwork, in source pixels, so the monogram does not
# sit hard against the rounded corners of the badge.
PADDING = 40

# A pixel counts as ink when its channels sum above this. The artwork is pure
# white on saturated navy, so anything near the midpoint separates them cleanly.
INK_THRESHOLD = 600


def background_colour(pixels, width, height):
    """The flat navy behind the mark, sampled from a corner."""
    return pixels[2, 2]


def ink_rows(pixels, width, height, step=4):
    """Rows containing a meaningful amount of white."""
    rows = []
    for y in range(0, height, step):
        hits = sum(1 for x in range(0, width, 4) if sum(pixels[x, y]) > INK_THRESHOLD)
        if hits / (width / 4) > 0.01:
            rows.append(y)
    return rows


def ink_columns(pixels, width, y0, y1, step=3):
    """Horizontal extent of white ink between two rows."""
    columns = [
        x for x in range(width)
        if any(sum(pixels[x, y]) > INK_THRESHOLD for y in range(y0, y1, step))
    ]
    return columns[0], columns[-1]


def widest_gap(pixels, width, height, rows, step=4):
    """
    The blank band separating the octagon from the wordmark.

    Taken as the widest run of empty rows in the lower half of the artwork,
    which is where that separation sits in this layout.
    """
    gaps, run = [], None
    for y in range(rows[0], rows[-1], step):
        blank = y not in rows
        if blank:
            run = (y, y) if run is None else (run[0], y)
        elif run:
            gaps.append(run)
            run = None
    if run:
        gaps.append(run)

    lower = [g for g in gaps if g[0] > height * 0.45]
    if not lower:
        raise SystemExit(
            'Could not find the gap between the monogram and the wordmark. '
            'The artwork layout has probably changed; adjust this script.'
        )
    return max(lower, key=lambda g: g[1] - g[0])


def squarify(image, background):
    """
    Pad to a square rather than crop.

    The wordmark runs almost the full width of the artwork, so a centre-crop
    would clip the M and the S.
    """
    side = max(image.size)
    canvas = Image.new('RGB', (side, side), background)
    canvas.paste(image, ((side - image.width) // 2, (side - image.height) // 2))
    return canvas


def main():
    if not SOURCE.exists():
        raise SystemExit(f'Source artwork not found: {SOURCE}')

    image = Image.open(SOURCE).convert('RGB')
    width, height = image.size
    pixels = image.load()

    background = background_colour(pixels, width, height)
    rows = ink_rows(pixels, width, height)
    top, bottom = rows[0], rows[-1]
    gap_start, gap_end = widest_gap(pixels, width, height, rows)

    print(f'source      : {SOURCE.name}  {width}x{height}')
    print(f'background  : #%02X%02X%02X' % background)
    print(f'artwork rows: {top} to {bottom}')
    print(f'monogram    : {top} to {gap_start}   (wordmark begins {gap_end})')

    # Full lockup: everything, trimmed to the artwork.
    x0, x1 = ink_columns(pixels, width, top, bottom)
    full = squarify(
        image.crop((max(0, x0 - PADDING), max(0, top - PADDING),
                    min(width, x1 + PADDING), min(height, bottom + PADDING))),
        background,
    )

    # Mark: the monogram alone. The bottom edge sits inside the blank band so
    # no sliver of lettering bleeds into it.
    mx0, mx1 = ink_columns(pixels, width, top, gap_start)
    mark = squarify(
        image.crop((max(0, mx0 - PADDING), max(0, top - PADDING),
                    min(width, mx1 + PADDING), gap_start + (gap_end - gap_start) // 2)),
        background,
    )

    print('\nwritten:')
    for folder in TARGETS:
        folder.mkdir(parents=True, exist_ok=True)
        for image_out, name, size in (
            (full, 'cde-manpower.png', FULL_PX),
            (mark, 'cde-manpower-mark.png', MARK_PX),
        ):
            path = folder / name
            image_out.resize((size, size), Image.LANCZOS).save(path, 'PNG', optimize=True)
            kb = path.stat().st_size / 1024
            print(f'  {path.relative_to(ROOT).as_posix():<46} {size}x{size}  {kb:6.1f} KB')


if __name__ == '__main__':
    main()
