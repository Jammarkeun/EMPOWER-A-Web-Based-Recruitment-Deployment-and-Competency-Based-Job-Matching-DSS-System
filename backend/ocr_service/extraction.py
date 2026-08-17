"""
Turns recognised text into applicant fields.

An honest note on what this can and cannot do. Values that follow a fixed shape —
email addresses, Philippine mobile numbers, SSS and PhilHealth numbers, dates —
can be extracted reliably, because the pattern itself is the evidence. Names,
addresses, and work history cannot: résumé layouts vary endlessly, and any rule
that appears to work on a handful of samples will fail on the next one.

The module therefore reports a confidence with every field, and the service never
writes anything to an applicant record directly. Extraction fills a form that HR
reviews and corrects, which fits the rest of the system: the computer proposes,
a person decides.
"""

from __future__ import annotations

import re
from datetime import datetime

# --------------------------------------------------------------------------
# Patterns
# --------------------------------------------------------------------------

EMAIL = re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b")

# Philippine mobile numbers: 09XXXXXXXXX, +639XXXXXXXXX, 639XXXXXXXXX, with any
# spacing, hyphens or brackets in between.
MOBILE = re.compile(r"(?:(?:\+?63)|0)[\s\-.]?9\d{2}[\s\-.]?\d{3}[\s\-.]?\d{4}\b")

# Landlines, written as (049) 501-2233 or 049-501-2233.
LANDLINE = re.compile(r"\(?0\d{2}\)?[\s\-.]?\d{3}[\s\-.]?\d{4}\b")

SSS = re.compile(r"\b\d{2}[\s\-]?\d{7}[\s\-]?\d\b")
PHILHEALTH = re.compile(r"\b\d{2}[\s\-]?\d{9}[\s\-]?\d\b")
TIN = re.compile(r"\b\d{3}[\s\-]?\d{3}[\s\-]?\d{3}(?:[\s\-]?\d{3,5})?\b")
PAGIBIG = re.compile(r"\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b")

DATE_PATTERNS = [
    # 12 January 1998 / 12 Jan 1998
    re.compile(r"\b(\d{1,2})\s+([A-Za-z]{3,9})\.?,?\s+(\d{4})\b"),
    # January 12, 1998
    re.compile(r"\b([A-Za-z]{3,9})\.?\s+(\d{1,2}),?\s+(\d{4})\b"),
    # 1998-01-12
    re.compile(r"\b(\d{4})-(\d{1,2})-(\d{1,2})\b"),
    # 12/01/1998 — ambiguous, handled with a day-first assumption below
    re.compile(r"\b(\d{1,2})/(\d{1,2})/(\d{4})\b"),
]

MONTHS = {
    "jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
    "jul": 7, "aug": 8, "sep": 9, "sept": 9, "oct": 10, "nov": 11, "dec": 12,
}

SEX_HINTS = {
    "male": "male", "m": "male", "lalaki": "male",
    "female": "female", "f": "female", "babae": "female",
}

CIVIL_STATUS_HINTS = {
    "single": "single", "married": "married", "widow": "widowed",
    "widowed": "widowed", "separated": "separated", "divorced": "divorced",
}

EDUCATION_HINTS = [
    (["doctor", "phd", "doctorate"], "postgraduate"),
    (["master", "graduate studies", "mba"], "postgraduate"),
    (["bachelor", "bs ", "ba ", "college graduate", "degree"], "college_graduate"),
    (["college level", "undergraduate", "college undergrad"], "college_undergraduate"),
    (["vocational", "tesda", "technical", "nc ii", "nc i", "certificate course"], "vocational"),
    (["senior high", "shs", "grade 12", "tvl", "abm", "humss", "stem", "gas"], "senior_high_school"),
    (["high school", "secondary"], "high_school"),
    (["elementary", "primary school"], "elementary"),
]

# Words that mark the start of the section they label, used to bound the search
# for a name to the top of the document.
SECTION_HEADINGS = [
    "objective", "career objective", "summary", "personal information",
    "personal data", "educational background", "education", "work experience",
    "employment history", "experience", "skills", "qualifications",
    "character reference", "references", "seminars", "trainings", "eligibility",
]

# Label noise that appears on ID cards and résumé headers and must never be
# mistaken for a person's name.
NON_NAME_TOKENS = {
    "republic", "philippines", "republika", "pilipinas", "identification",
    "card", "license", "licence", "driver", "passport", "postal", "unified",
    "multi", "purpose", "id", "social", "security", "system", "philhealth",
    "pagibig", "pag-ibig", "sss", "tin", "umid", "philsys", "resume",
    "curriculum", "vitae", "biodata", "bio-data", "data", "personal",
    "information", "name", "address", "contact", "number", "email", "date",
    "birth", "place", "civil", "status", "sex", "gender", "nationality",
    "citizenship", "height", "weight", "religion",
}


def extract(lines: list[str], document_type: str = "auto") -> dict:
    """
    Extract applicant fields from recognised text lines.

    Returns a mapping of field name to {value, confidence, source}, where source
    names the line the value came from so HR can see the evidence.
    """
    text = "\n".join(lines)
    lowered = text.lower()

    if document_type == "auto":
        document_type = _detect_document_type(lowered)

    fields: dict = {}

    _extract_contact(text, fields)
    _extract_government_ids(text, fields)
    _extract_dates(text, lowered, fields)
    _extract_demographics(lowered, fields)
    _extract_education(lowered, fields)
    _extract_name(lines, fields, document_type)
    _extract_address(lines, fields)

    return {
        "document_type": document_type,
        "fields": fields,
        # Nothing is applied automatically. This flag is what the frontend uses
        # to decide how loudly to ask for review.
        "needs_review": True,
        "low_confidence_fields": [
            name for name, data in fields.items() if data["confidence"] < 0.6
        ],
    }


# --------------------------------------------------------------------------
# Field extractors
# --------------------------------------------------------------------------


def _extract_contact(text: str, fields: dict) -> None:
    if match := EMAIL.search(text):
        # Emails are unambiguous once matched: the pattern is the proof.
        fields["email"] = _field(match.group(0).lower(), 0.95, match.group(0))

    if match := MOBILE.search(text):
        digits = re.sub(r"\D", "", match.group(0))
        # Normalise +63/63 prefixes to the local 09 form the office uses.
        if digits.startswith("63"):
            digits = "0" + digits[2:]
        if len(digits) == 11 and digits.startswith("09"):
            fields["contact_number"] = _field(digits, 0.9, match.group(0))

    elif match := LANDLINE.search(text):
        fields["contact_number"] = _field(match.group(0).strip(), 0.7, match.group(0))


def _extract_government_ids(text: str, fields: dict) -> None:
    """
    Government numbers share digit shapes, so each is only accepted when its own
    label appears nearby. Matching on shape alone would routinely mistake a TIN
    for a Pag-IBIG number.
    """
    for label, pattern, key in (
        ("sss", SSS, "sss_number"),
        ("philhealth", PHILHEALTH, "philhealth_number"),
        ("pag-ibig", PAGIBIG, "pagibig_number"),
        ("pagibig", PAGIBIG, "pagibig_number"),
        ("hdmf", PAGIBIG, "pagibig_number"),
        ("tin", TIN, "tin_number"),
    ):
        for line in text.split("\n"):
            if label not in line.lower():
                continue
            if match := pattern.search(line):
                fields[key] = _field(match.group(0).strip(), 0.85, line.strip())
                break


def _extract_dates(text: str, lowered: str, fields: dict) -> None:
    """
    Look for a date of birth, preferring one that sits on a labelled line.
    """
    for line in text.split("\n"):
        line_lower = line.lower()
        if not any(hint in line_lower for hint in ("birth", "born", "dob", "kapanganakan")):
            continue

        if parsed := _parse_any_date(line):
            # Sanity check: an applicant is a working-age adult, so a date
            # implying an age outside 15–75 is almost certainly a different date
            # that happened to sit on the line.
            age = _age_from(parsed)
            if 15 <= age <= 75:
                fields["birth_date"] = _field(parsed, 0.85, line.strip())
                return

    # No labelled line; fall back to any date that yields a plausible age.
    if parsed := _parse_any_date(text):
        age = _age_from(parsed)
        if 15 <= age <= 75:
            fields["birth_date"] = _field(parsed, 0.45, "inferred from an unlabelled date")


def _parse_any_date(text: str) -> str | None:
    for index, pattern in enumerate(DATE_PATTERNS):
        match = pattern.search(text)
        if not match:
            continue

        try:
            if index == 0:  # 12 January 1998
                day, month_name, year = match.groups()
                month = MONTHS.get(month_name[:3].lower())
                if not month:
                    continue
                return f"{int(year):04d}-{month:02d}-{int(day):02d}"

            if index == 1:  # January 12, 1998
                month_name, day, year = match.groups()
                month = MONTHS.get(month_name[:3].lower())
                if not month:
                    continue
                return f"{int(year):04d}-{month:02d}-{int(day):02d}"

            if index == 2:  # 1998-01-12
                year, month, day = match.groups()
                return f"{int(year):04d}-{int(month):02d}-{int(day):02d}"

            # 12/01/1998 — read day-first, the dominant Philippine convention.
            day, month, year = match.groups()
            if int(month) > 12:  # clearly month-first after all
                day, month = month, day
            if int(month) > 12 or int(day) > 31:
                continue
            return f"{int(year):04d}-{int(month):02d}-{int(day):02d}"
        except (ValueError, TypeError):
            continue

    return None


def _age_from(iso_date: str) -> int:
    try:
        born = datetime.strptime(iso_date, "%Y-%m-%d")
    except ValueError:
        return -1

    today = datetime.now()
    return today.year - born.year - ((today.month, today.day) < (born.month, born.day))


def _extract_demographics(lowered: str, fields: dict) -> None:
    for line in lowered.split("\n"):
        if any(k in line for k in ("sex", "gender", "kasarian")) and "sex" not in fields:
            for token, value in SEX_HINTS.items():
                if re.search(rf"\b{re.escape(token)}\b", line):
                    fields["sex"] = _field(value, 0.8, line.strip())
                    break

        if any(k in line for k in ("civil status", "marital", "katayuan")) and "civil_status" not in fields:
            for token, value in CIVIL_STATUS_HINTS.items():
                if token in line:
                    fields["civil_status"] = _field(value, 0.8, line.strip())
                    break


def _extract_education(lowered: str, fields: dict) -> None:
    """
    Report the highest attainment mentioned anywhere in the document.

    Ordered most-qualified first, so a résumé listing both a degree and the
    secondary school that preceded it resolves to the degree.
    """
    for keywords, level in EDUCATION_HINTS:
        for keyword in keywords:
            if keyword in lowered:
                line = next(
                    (l.strip() for l in lowered.split("\n") if keyword in l),
                    keyword,
                )
                fields["education_level"] = _field(level, 0.65, line)
                return


def _extract_name(lines: list[str], fields: dict, document_type: str) -> None:
    """
    Guess the person's name from the top of the document.

    Genuinely unreliable, and scored accordingly. The heuristic is that a résumé
    usually opens with the name before any section heading, often in capitals,
    and that the line contains no label vocabulary or digits.
    """
    for raw in lines[:12]:
        line = raw.strip()

        if not (4 < len(line) < 60):
            continue
        if any(char.isdigit() for char in line):
            continue
        if any(heading in line.lower() for heading in SECTION_HEADINGS):
            continue

        words = [w for w in re.split(r"[\s,]+", line) if w]
        if not (2 <= len(words) <= 5):
            continue
        if any(w.lower().strip(".") in NON_NAME_TOKENS for w in words):
            continue
        if not all(re.fullmatch(r"[A-Za-zÑñ.'\-]+", w) for w in words):
            continue

        # Fully capitalised or title-cased lines are the usual presentation.
        is_upper = line.isupper()
        is_title = all(w[0].isupper() for w in words if w)
        if not (is_upper or is_title):
            continue

        parts = _split_name(words)
        confidence = 0.6 if is_upper else 0.45

        fields["last_name"] = _field(parts["last"], confidence, line)
        fields["first_name"] = _field(parts["first"], confidence, line)
        if parts["middle"]:
            fields["middle_name"] = _field(parts["middle"], confidence - 0.1, line)
        return


def _split_name(words: list[str]) -> dict:
    """
    Split name words into first, middle, and last.

    Philippine résumés are written first-name-first, so the final word is taken
    as the surname and anything between as a middle name.
    """
    cleaned = [w.strip(".,").title() for w in words]

    if len(cleaned) == 2:
        return {"first": cleaned[0], "middle": None, "last": cleaned[1]}

    return {
        "first": cleaned[0],
        "middle": " ".join(cleaned[1:-1]) or None,
        "last": cleaned[-1],
    }


def _extract_address(lines: list[str], fields: dict) -> None:
    """
    Find an address by looking for Philippine locality vocabulary.
    """
    markers = (
        "brgy", "barangay", "purok", "sitio", "street", "st.", "ave", "avenue",
        "subdivision", "subd", "city", "municipality", "province", "laguna",
        "zone", "block", "lot", "phase",
    )

    for raw in lines:
        line = raw.strip()
        if not (10 < len(line) < 140):
            continue

        lowered = line.lower()
        if any(label in lowered for label in ("address", "tirahan")):
            cleaned = re.sub(
                r"^.*?(?:address|tirahan)\s*[:\-]?\s*", "", line, flags=re.IGNORECASE
            ).strip()
            if len(cleaned) > 8:
                fields["present_address"] = _field(cleaned, 0.7, line)
                return

        hits = sum(1 for marker in markers if marker in lowered)
        if hits >= 2 and "present_address" not in fields:
            fields["present_address"] = _field(line, 0.5, line)


def _detect_document_type(lowered: str) -> str:
    if any(k in lowered for k in ("curriculum vitae", "resume", "résumé", "bio-data", "biodata")):
        return "resume"

    # Many résumés never use the word "resume" anywhere on the page, so the
    # structure is a better signal than the title. Two or more of the standard
    # section headings is something no ID card or certificate contains, and
    # without this check a résumé mentioning a degree gets classified as a
    # diploma by the keyword rules below.
    heading_hits = sum(
        1
        for heading in (
            "educational background", "work experience", "employment history",
            "personal information", "personal data", "character reference",
            "references", "skills", "seminars", "trainings", "objective",
            "qualifications", "eligibility",
        )
        if heading in lowered
    )
    if heading_hits >= 2:
        return "resume"

    if any(k in lowered for k in ("driver's license", "drivers license", "lto")):
        return "drivers_license"
    if any(k in lowered for k in ("unified multi-purpose", "umid", "social security system")):
        return "umid"
    if "philsys" in lowered or "philippine identification" in lowered:
        return "philsys"
    if "philhealth" in lowered:
        return "philhealth_id"
    if any(k in lowered for k in ("birth certificate", "certificate of live birth", "psa")):
        return "birth_certificate"
    if any(k in lowered for k in ("diploma", "bachelor of", "has satisfactorily completed")):
        return "diploma"
    if any(k in lowered for k in ("national certificate", "tesda")):
        return "tesda_certificate"
    return "unknown"


def _field(value, confidence: float, source: str) -> dict:
    return {
        "value": value,
        "confidence": round(confidence, 2),
        "source": source[:160] if isinstance(source, str) else str(source),
    }
