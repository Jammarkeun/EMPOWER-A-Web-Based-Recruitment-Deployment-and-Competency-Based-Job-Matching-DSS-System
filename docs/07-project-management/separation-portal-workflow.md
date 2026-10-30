# Resignation requests and separation notices in the portal

Built 29 October 2026 on `feature/cde-requirements-integration`. This note says
what was wrong, what the workflow is now, which rules are CDE's and which are
ours, and what CDE still has to decide.

## 1. What was wrong

| Finding | Cause |
|---|---|
| Deployed employees could not find the resignation request at all | Deployment creates the employee but never changes the login. A login made at registration stays `applicant`, and the portal showed **My employment** only to `employee` logins. On the local demo data, all six portal logins with an employment record were `applicant` logins. |
| The employee chose their own filing date and notice period | The portal form posted `filing_date` and `rendering_days` (defaulting to 30) as typed |
| HR was not told when a request arrived | No notification on filing |
| A withdrawn request blocked any new one | The page offered the form only when there was no resignation at all |
| An employee could not see HR's response, the next step, or a history | Only status, clearance and exit date were sent |
| A finalised termination sent "Your application status has changed" | The generic status notification, nothing written for a termination |
| The portal's activity list showed `Terminated: <the office's reason>` | The applicant history entry was written with the internal reason |
| HR could not tell whether the employee had seen a termination | Nothing recorded |
| Clearance was recorded for resignations only | CDE's clearance follows every separation |

What was **not** wrong: drafts and terminations under review never reached the
employee; violation reports, incidents and the violation threshold never change
employment status; the staff routes already refused portal accounts; every
separation already wrote status history, an archive snapshot and audit entries.

## 2. The workflow now

**Resignation**

1. *Request*. The employee sends it from **My employment**: last day they
   intend to work, reason, optional remarks, optional letter, and an
   acknowledgment that the request does not end their employment. The
   employee, placement and filing date are taken from the server. HR and
   administrators are notified. Employment status does not change.
2. *Review*. HR may ask for more information (the employee sees HR's message
   and replies in the portal), accept it with a confirmed last working day, or
   close it with an internal reason. The employee may withdraw it until HR
   accepts it.
3. *Clearance*. Recorded by HR; the employee sees it once the request is
   accepted.
4. *Completion*. HR completes it (with confirmation) once cleared and dated:
   employee and applicant become `resigned`, the placement ends on the exit
   date, the record is archived. The employee is told it is complete.

**Termination**

1. Filed by HR for review. The employee is told nothing.
2. Finalised by an administrator (with the employee number typed). This is the
   only event that notifies the employee. The notice shows the effective date,
   the message HR wrote for the employee (optional), clearance, next steps and
   the office contact, never the reason, remarks or documents.
3. Clearance is then recorded by HR and shown to the employee.
4. HR can see four separate facts: the notification was created, it was read,
   the notice was opened, the employee confirmed receipt. Confirming receipt is
   not agreement and nothing waits for it.

Every step is written to `separation_events`, with the entries the employee
may see marked as such, and to the audit log.

## 3. Rules that are ours, not CDE's

| # | Rule | Why |
|---|---|---|
| S1 | No "rejected" outcome. A request filed in error is closed as withdrawn, with who closed it and why | Under the Labor Code an employee may resign on a month's notice; CDE has not described refusing one |
| S2 | The employee may withdraw only before HR accepts; afterwards only HR records a withdrawal | Philippine practice: an accepted resignation is no longer the employee's alone to take back |
| S3 | The 30-day notice is guidance, never enforced | CDE: "ayon sa batas, meron tayong 30 days render". A shorter notice is warned about and HR records what was agreed (`config/empower.php → separation.notice_days`) |
| S4 | A reason is required | Unchanged from before; both entry points already required it |
| S5 | Accepting needs a confirmed last working day | So "accepted" always tells the employee when they finish |
| S6 | No completion while HR is waiting on the employee for information | That question is answered first |
| S7 | A message to the employee is a separate field from the office's remarks and reasons | The employee sees the first, never the others |
| S8 | Post-separation, the portal stays open and read-only for the person's own record; no account is deactivated automatically | CDE has not said when access should end (question C3) |
| S9 | Termination clearance is recorded after finalisation and does not hold it up | The dismissal takes effect on the decision; clearance settles obligations |
| S10 | Requested last day: today up to one year ahead | A typo guard, not a policy |

## 4. Questions for CDE

- **C1** Can the agency refuse or defer a resignation, or only agree the last
  day? (S1)
- **C2** After HR accepts a resignation, may the employee still withdraw it? (S2)
- **C3** How long should a former employee keep portal access, and should a
  terminated employee's access end sooner?
- **C4** Is a resignation letter required, or is the portal request enough?
- **C5** What may the employee be told about the grounds of a termination, and
  who writes it? (Today HR writes an optional message at finalisation.)
- **C6** If an employee resigns while a termination is under review, what
  happens to the termination? (Part 3, question 16.5; still open. Both cases can
  exist, and the employee is not told about the termination.)
- **C7** Is the clearance after a termination the same as after a resignation?

## 5. Database

Migration `2026_10_29_000600_extend_separation_workflow` adds columns to
`resignations` and `terminations` and creates `separation_events`. It refuses to
run if an employee already has two open resignations.

- Rehearsed on a restored copy of the local `empower` database (up, down, up),
  and the separation tests were run against MariaDB 10.4 as well as SQLite.
- Applied to local MariaDB `empower` on 29 October 2026 after a full backup
  (`C:\Users\JM\Downloads\CAPSTONE\EMPOWER-db-backups\empower-2026-10-29-before-separation-migration.sql`).
- **Supabase has not been migrated.** Run `php artisan migrate` there before
  deploying, or the employee record, the separations list and the portal's
  employment page fail.
- Terminations finalised before this show their notice in the portal with
  "not sent" for the notification, because none was sent at the time.
