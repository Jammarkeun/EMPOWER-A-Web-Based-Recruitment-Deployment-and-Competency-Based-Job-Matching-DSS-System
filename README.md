# EMPOWER

**A Web-Based Recruitment, Deployment, and Competency-Based Job Matching Decision Support System**

Built for **CDE Manpower Services**, Sta. Cruz, Laguna.
Capstone project, Laguna State Polytechnic University.

---

## The problem

The agency keeps applicant records in physical folders, employee details across
Excel and Google Sheets, and disciplinary records apart from both. Nothing joins
them, so answering "who is ready to deploy for this request?" means a person
going through folders by hand.

EMPOWER puts the whole employment lifecycle on one record — from an applicant
walking into the office through to their placement, and on to separation.

## What it does

| | |
| --- | --- |
| **Manpower requests** | Client requests recorded against a department, with headcount tracked as workers are deployed. |
| **Applicant lifecycle** | Application → screening → requirements → training → client evaluation → deployment, every change dated and attributed. |
| **The folder system, digitally** | The agency's Folder 1 / 2 / 3 filing, derived automatically from the documents actually verified. No manual re-filing. |
| **Competency matching** | Ranks eligible applicants against criteria HR sets per position, and shows how every point was awarded. |
| **Employment and separation** | An applicant becomes an employee without losing their recruitment history; resignations and terminations run to archive. |
| **Self-service portal** | Applicants register, see which documents to bring, send copies ahead, and follow their own progress. |
| **Reports** | PDF and Excel on agency letterhead, for client meetings. |

Payroll is deliberately out of scope.

## The matching engine is not AI

This matters enough to state plainly. Scoring is **rule-based**. HR sets what
counts for a position and what each factor is worth; the system measures each
applicant against those criteria and shows the arithmetic.

Every score traces back to a weight a named officer entered. That is what makes
a recommendation defensible — to the client company, and to the applicant who
was not selected.

**The system recommends. It never hires.** Deployment stays a separate,
deliberate act by a person.

## Built with

| Layer | |
| --- | --- |
| API | Laravel 12, PHP 8.3, Sanctum tokens, spatie/laravel-permission |
| Database | Supabase (PostgreSQL 17) |
| Documents | Supabase Storage, private bucket, expiring signed URLs |
| Frontend | React 18, Vite, Tailwind CSS, Chart.js |
| Document reading | FastAPI + PaddleOCR |
| Reports | dompdf, PhpSpreadsheet |

## Running it

**Install first** — none of these are in the repository:

| | |
| --- | --- |
| PHP **8.2+** | 8.0 will not run Laravel 12. If XAMPP is installed, make sure the newer PHP comes first on your PATH. |
| Composer | Installs the PHP dependencies. |
| Node **18+** | |
| Python **3.10+** | Only for document reading, which is optional. |

Then, from the repository root:

```bash
# 1. Dependencies. Do this before anything else - the artisan commands below
#    do not exist until vendor/ is installed.
composer install --working-dir=backend/laravel
npm install --prefix frontend

# 2. Configuration
cp backend/laravel/.env.example backend/laravel/.env

# 3. Fill in the blanks in backend/laravel/.env
#    The Supabase credentials are NOT in this repository. Ask a teammate to
#    send them privately, or create your own Supabase project and use its
#    values. Every variable is explained in the file itself.

# 4. Application key
php backend/laravel/artisan key:generate

# 5. Schema and reference data. Only needed once per database - if a teammate
#    has already done it against the shared project, skip this.
php backend/laravel/artisan migrate --seed
```

On Windows, **double-click `run.cmd`** — or from a terminal:

```
.\run.cmd            # API + frontend + OCR
.\run.cmd -NoOcr     # skip document reading
.\run.cmd -Stop      # shut everything down
```

It wraps `start.ps1`. Use the wrapper rather than the `.ps1` directly: Windows
ships with the PowerShell execution policy set to `Restricted`, which refuses
to run any script file, and `run.cmd` bypasses that for the single command
instead of requiring a change to the machine's security settings.

Otherwise, in three terminals:

```bash
php artisan serve                       # API        :8000
npm run dev --prefix frontend           # Frontend   :5173
python backend/ocr_service/main.py      # OCR        :8001
```

```bash
php artisan test                        # 97 tests
```

## Handling personal data

The system holds personal and sensitive personal information covered by the
**Data Privacy Act of 2012 (RA 10173)**. Accordingly:

- Documents live in a private bucket and are reachable only through signed URLs
  that expire within minutes — never a public path.
- Access is role-based, and the portal resolves every record from the signed-in
  user rather than from an identifier in the URL, so there is nothing to tamper
  with.
- Every consequential action is written to an audit trail with who did it and
  what changed.
- The site is marked `noindex`, and no credentials are committed to this
  repository.

## Documentation

Design documents, the API contract, and the project timeline are under
[`docs/`](docs/).

---

Developed as a capstone project in collaboration with CDE Manpower Services.
