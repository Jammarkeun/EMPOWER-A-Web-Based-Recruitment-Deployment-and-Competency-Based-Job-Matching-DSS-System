# Brand assets

`cde-manpower-original.png` is the logo as CDE Manpower Services supplied it:
1072×1008, roughly 1 MB. It is the source of record — keep it, and do not edit
it in place.

Everything the application uses is derived from it by `generate-logo.py`. That
script is the only thing that should ever write to the `logo/` folders.

| Derived file | Size | Used for |
| --- | --- | --- |
| `cde-manpower.png` | 512×512 | Full lockup — sign-in, registration, iOS home-screen icon |
| `cde-manpower-mark.png` | 256×256 | Octagon only — sidebar, page headers, browser tab, PDF letterhead |

Both land in `frontend/public/logo/` and `backend/laravel/public/logo/`.

These notes live here rather than beside the images because everything under
`frontend/public/` is served to the open internet, and internal documentation
does not belong on a public URL.

## Why the original is not used directly

**It is not square.** 1072×1008. Every placement in the interface is a square
badge, so an un-squared source is either distorted or centre-cropped, and a
centre-crop clips the M and the S of MANPOWER SERVICES.

**It is far too heavy.** One megabyte for artwork that renders between 28 and
64 pixels. It loads on the public landing page and the registration page, which
are exactly the pages an applicant opens on mobile data. The derivatives are
175 KB and 48 KB.

**The wordmark does not survive small sizes.** In the sidebar the lockup is
about 28px tall, which leaves the words MANPOWER SERVICES roughly one pixel
high. It reads as a smudge and makes the whole badge look out of focus, so a
separate mark-only crop exists for those placements.

## Regenerating

Run from the repository root, with the project virtualenv active:

```
.venv/Scripts/python.exe docs/08-brand/generate-logo.py
```

It writes four files — the full lockup and the mark, into both
`frontend/public/logo/` and `backend/laravel/public/logo/`. The two copies are
identical; the frontend and the API deploy independently, so the PDF letterhead
cannot reach the frontend's copy.

## If the agency supplies new artwork

Replace `cde-manpower-original.png` and re-run the script. If the new artwork
has a different layout — no wordmark, or the wordmark beside the octagon rather
than beneath it — the row boundaries the script detects will differ, so check
both outputs by eye afterwards. The script prints the boundaries it found.
