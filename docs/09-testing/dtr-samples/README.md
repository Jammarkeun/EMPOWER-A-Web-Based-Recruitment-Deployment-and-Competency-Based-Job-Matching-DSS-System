# Synthetic DTR sample files

Test files for EMPOWER's **DTR import** and **DTR-to-schedule comparison**.

> **Everything here is invented.** The workers, biometrics numbers, client
> companies and times are synthetic. They are not CDE records or a real
> client's export, and they must not be presented as either. No sample of a
> client's actual biometric export has been received yet.

## What the importer accepts

This is what the current importer actually accepts (`DtrImportService`,
`DtrFileReader`). The sample files were written to fit it; the importer was not
changed for them.

| | |
| --- | --- |
| File types | `.csv` (comma, semicolon, tab or pipe separated), `.txt`, `.xlsx` and `.xls` (first sheet only). Up to 5 MB and 10,000 rows. |
| Layout | **No fixed template.** On the import screen HR chooses which column is which: employee identifier, date (and its format), time-in, time-out, and optionally name and remarks. The screen suggests a mapping from the header names. |
| Rows | **One row per employee per day.** A raw punch log (one row per punch) is not supported. |
| Identifier | The biometrics number or the employee number. It is matched only among workers placed at the chosen client during the period. Leading zeros are ignored. |
| Dates | One format per file, chosen from the list on the screen (`Y-m-d`, `m/d/Y`, `d/m/Y`, `d-M-Y`, …). |
| Times | `HH:MM`, `HH:MM:SS`, `h:mm AM/PM`. A time-out at or before the time-in is read as the next morning, which is how a night shift reads. |
| Period | Each upload states the client and the period it covers (at most 62 days, ending today or earlier), and is filed under that client's **DTR period**. Importing does not mark the DTR received: scheduled days with no row stay **Awaiting DTR** until HR confirms the DTR period complete (DTR imports → DTR periods). |
| Safeguards | Nothing is written until HR has previewed the file and confirmed it. A day that differs from what is already on file is only replaced if HR chooses Replace and gives a reason. The same file cannot be imported twice. |

The sample files use this header, which the screen maps correctly by itself:

```
Biometric No., Employee Name, Date, Time In, Time Out, Remarks
```

The date format is `Y-m-d` (for example `2026-10-21`) and times are 24-hour
`HH:MM`. Remarks start with `TEST:` and name the scenario.

## Files

| File | Purpose | Period to enter when importing |
| --- | --- | --- |
| `EMPOWER_DTR_Valid_Test_Data.csv` | **File 1 – valid.** 15 rows for 3 workers covering the normal cases and the common discrepancies. | 2026-10-19 to 2026-10-25 |
| `EMPOWER_DTR_Valid_Test_Data.xlsx` | The same DTR as an Excel workbook, with real date and time cells. Use it instead of the CSV, not as well: the second import finds every day already on file. | 2026-10-19 to 2026-10-25 |
| `EMPOWER_DTR_Invalid_Test_Data.csv` | **File 2 – negative test.** 12 rows, each with a different fault. Nothing in it can be imported. | 2026-10-19 to 2026-10-25 |
| `EMPOWER_DTR_Duplicate_Test_Data.csv` | **File 3 – duplicates.** Repeated and conflicting rows. Import it **after** File 1. | 2026-10-19 to 2026-11-01 |
| `EMPOWER_DTR_Expected_Day_Results.csv` | What the comparison must show for each worker and day of the test week. | — |
| `EMPOWER_DTR_Expected_Row_Outcomes.csv` | What the preview must say about every row of every file. | — |
| `EMPOWER_DTR_Expected_Summary.csv` | Each worker's weekly figures. | — |
| `generate_dtr_samples.php` | Writes all of the above. The expected results are written into it by hand, from the documented rules. | — |

Regenerate the files with `php docs/09-testing/dtr-samples/generate_dtr_samples.php`.

## The synthetic workers and their approved schedules

They are created by `DtrSampleSeeder`, a development-only seeder. It refuses
to run in production and does nothing if its client already exists. Each
worker goes through the real path:

1. Requirements verified and training completed.
2. Deployed on **1 October 2026** through the deployment service.
3. Given a schedule.

For the workers at the sample client, the schedule is **submitted by a
synthetic coordinator and approved by HR** through the schedule approval
workflow. The fifth worker's schedule is set by HR directly.

| Biometric No. | Name | Client | Approved schedule (from 2026-10-01) | Role in the test |
| --- | --- | --- | --- | --- |
| 90001 | Alma Dizon | DTR Sample Client Co. | Mon–Fri 08:00–17:00, break 12:00 (60 min) | Day shift: normal, late, early, missing time-in, rest-day work |
| 90002 | Benjie Ocampo | DTR Sample Client Co. | Mon–Fri 22:00–06:00 (overnight) | Night shift, missing time-out, no DTR record |
| 90003 | Carla Mendoza | DTR Sample Client Co. | Mon–Sat 07:00–16:00, break 11:00 (60 min) | Late by 1 min (grace 0), no DTR record, Saturday workday |
| 90004 | Dario Villanueva | DTR Sample Client Co. | Mon–Fri 08:00–17:00 | Not in any file: stays **Awaiting DTR** |
| 90005 | Elena Bautista | DTR **Other** Client Inc. | Mon–Fri 08:00–17:00 | Placed elsewhere: refused as "not placed at this client" |

The seeder also creates a coordinator account, `coordinator.dtrsample@example.test`,
whose password nobody knows. An administrator can reset it to sign in as the
coordinator.

### Putting them in a database

```
cd backend/laravel
php artisan db:seed --class=DtrSampleSeeder
```

This needs an active administrator and an active HR user to act as. It adds two
synthetic client companies, five applicants and employees, one coordinator, and
their approved schedules. It never touches existing records. The HR users
receive the usual "work schedule submitted" notifications.

**Decide for yourself whether to run it on your demo database (`empower`).**
It has not been run there. The automated test creates the same records in its
own throwaway database every time it runs.

## How to import (manual, in the app)

Sign in as HR, then go to **Attendance (DTR) → DTR imports → Import DTR file**.

1. **File 1.** Choose *DTR Sample Client Co. (Synthetic Test Data)*, period
   **19 Oct 2026 to 25 Oct 2026**, and pick `EMPOWER_DTR_Valid_Test_Data.csv`.
   Click **Upload and choose columns**.
2. **Check the columns.** The suggested mapping should read:
   - Identifier: Biometrics number, column A
   - Name: B
   - Date: C, format `2026-09-07`
   - Time in: D
   - Time out: E
   - Remarks: F

   Click **Check rows**.
3. **Check the preview.** It should show 15 rows read, 15 valid, 0 invalid,
   0 unmatched, 0 duplicates, 0 outside the period, and 3 employees in the
   file. *Dario Villanueva* is listed as having no rows.
4. Click **Continue**, read the confirmation, then **Import 15 day(s)**. It
   should report 15 days imported, and the DTR period 19–25 Oct as
   *Processing*.
5. Open **Review queue** for October 2026 and filter to the sample client. It
   should show 8 days *For review* — the days with times on them. Benjie's
   Thu 22, Carla's Wed 21 and all of Dario's weekdays are **Awaiting DTR**.
5a. Open **DTR imports → DTR periods**, open 19–25 Oct, and check the
   reconciliation: 4 employees placed, Dario with 0 days on file, 7 days
   awaiting the DTR. Click **Confirm complete for period**, tick the
   acknowledgement, and confirm. The review queue now shows **15 days** *For
   review* (see below).
6. Open **Employees** for October 2026, then each worker's **Attendance (DTR)**
   tab, to see each day and the weekly figures.
7. **File 2.** Upload the invalid file with the same period and check its rows.
   Every row is marked with its fault. The Import button stays disabled,
   because nothing can be imported; the server refuses the import as well
   (checked by the automated test). Discard the draft.
8. **File 3.** Upload the duplicate file with the period **19 Oct – 1 Nov**,
   check its rows, then import with "Leave the DTR on file as it is". Those
   dates reach past the confirmed week, so they open a DTR period of their own.
9. Try to upload File 1 again. It is refused: the same file was already
   imported. A different file for 19–25 Oct would also be refused until HR
   reopens that period, because it is confirmed complete.

## Expected results

### Test week, day by day (after File 1 and confirming the period complete)

`For review` means the day is a flagged discrepancy waiting in HR's review
queue. It is not a finding. Before the period is confirmed, every **No DTR
record** day below reads **Awaiting DTR** instead, with no review — the
`status_before_period_confirmed` column of the expected-results file.

| Worker | Date | Schedule | In | Out | Expected | Discrepancy | Review |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Alma 90001 | Mon 19 | 08:00–17:00 | 07:52 | 17:03 | On schedule (3 min after end, info only) | — | — |
| | Tue 20 | 08:00–17:00 | 08:00 | 17:00 | On schedule | — | — |
| | Wed 21 | 08:00–17:00 | 08:17 | 17:00 | Discrepancy | Late 17 min | For review |
| | Thu 22 | 08:00–17:00 | 08:00 | 16:30 | Discrepancy | Early departure 30 min | For review |
| | Fri 23 | 08:00–17:00 | — | 17:00 | Discrepancy | Missing time-in | For review |
| | Sat 24 | rest day | 09:00 | 13:00 | Discrepancy | Outside assigned schedule | For review |
| | Sun 25 | rest day | — | — | Rest day | — | — |
| Benjie 90002 | Mon 19 | 22:00–06:00 | 21:55 | 06:02 (+1) | On schedule | — | — |
| | Tue 20 | 22:00–06:00 | 22:12 | 06:00 (+1) | Discrepancy | Late 12 min | For review |
| | Wed 21 | 22:00–06:00 | 22:00 | — | Discrepancy | Missing time-out | For review |
| | Thu 22 | 22:00–06:00 | no row | | Discrepancy | **No DTR record** (period confirmed complete, day blank; not an absence) | For review |
| | Fri 23 | 22:00–06:00 | 22:00 | 05:30 (+1) | Discrepancy | Early departure 30 min | For review |
| | Sat 24, Sun 25 | rest days | — | — | Rest day | — | — |
| Carla 90003 | Mon 19 | 07:00–16:00 | 06:58 | 16:00 | On schedule | — | — |
| | Tue 20 | 07:00–16:00 | 07:01 | 16:00 | Discrepancy | Late 1 min (grace period is 0) | For review |
| | Wed 21 | 07:00–16:00 | no row | | Discrepancy | No DTR record | For review |
| | Thu 22 | 07:00–16:00 | 07:00 | 16:05 | On schedule (5 min after end, info only) | — | — |
| | Fri 23, Sat 24 | 07:00–16:00 | 07:00 | 16:00 | On schedule (Saturday is her workday) | — | — |
| | Sun 25 | rest day | — | — | Rest day | — | — |
| Dario 90004 | Mon 19 – Fri 23 | 08:00–17:00 | no rows | | Discrepancy | **No DTR record** (not in the file, but HR confirmed the client's DTR for the week is complete; for verification, not an absence) | For review |
| | Sat 24, Sun 25 | rest days | — | — | Rest day | — | — |

### Weekly figures

These appear on the worker's DTR tab and in the Employees summary, once the period is confirmed complete.

| Worker | Scheduled | Compared | On schedule | Late | Early | Missing punch | No DTR record | Outside schedule | Awaiting DTR | Data |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Alma | 5 | 5 | 2 | 1 (17m) | 1 (30m) | 1 | 0 | 1 | 0 | complete |
| Benjie | 5 | 5 | 1 | 1 (12m) | 1 (30m) | 1 | 1 | 0 | 0 | complete |
| Carla | 6 | 6 | 4 | 1 (1m) | 0 | 0 | 1 | 0 | 0 | complete |
| Dario | 5 | 5 | 0 | 0 | 0 | 0 | 5 | 0 | 0 | complete |

The review queue for the sample client and the week holds **15 days**, all *For review*.

### File 2 – every row refused

| Line | Fault | Preview says |
| --- | --- | --- |
| 2 | Date `19/10/2026` is not in the chosen `Y-m-d` format | Date not in the chosen format |
| 3 | Time-in `8:75` | Time could not be read |
| 4 | Time-out `25:30` | Time could not be read |
| 5 | Blank biometrics number | No employee identifier |
| 6 | Biometrics `99999` | No such employee |
| 7 | Blank date | No date |
| 8 | No time-in and no time-out | No time-in or time-out (skipped, not counted as an error) |
| 9 | 90001 with the name "REYES, JUAN" | Name does not match the employee |
| 10 | 30 Oct, after the file's period | Outside the period of this file |
| 11 | Time-out 08:00 before time-in 17:00 on the same date | Time could not be read |
| 12 | 90005, placed at the other client | Not placed at this client on that day |
| 13 | `*** END OF REPORT ***` footer (malformed row) | No such employee |

The preview shows 0 ready and 11 problems. The Import button is disabled, the
server also refuses the import, and no time record is written.

### File 3 – duplicates (after File 1)

| Line | Row | Preview says | After import ("Leave the DTR on file as it is") |
| --- | --- | --- | --- |
| 2 | Alma 19 Oct, same times as on file | Already on file | Nothing written |
| 3 | Alma 20 Oct, 08:05 (08:00 on file) | Differs from the DTR on file | Kept at 08:00 |
| 4 | Alma 26 Oct | Ready to import | Imported |
| 5 | Carla 26 Oct | Ready to import | Imported once |
| 6 | Carla 26 Oct again, same times | Repeated in the file | Skipped |
| 7–8 | Carla 27 Oct twice, different times | Listed twice with different times | Neither imported |

The import summary should read: 2 imported, 1 already on file, 2 skipped,
2 problems, 0 replaced. Carla's 27 Oct then shows **Awaiting DTR**: neither of
its rows could be trusted. Once HR confirms the 19 Oct – 1 Nov period
complete it shows **No DTR record**, for verification.

## Automated verification

`backend/laravel/tests/Feature/DtrSampleFilesTest.php` does the import the
way HR does: it creates the workers with the seeder, uploads each file through
the real endpoints, accepts the suggested columns, previews, confirms, and
compares everything the system then shows with the three expected-results files.
It does not insert attendance rows itself.

```
cd backend/laravel
php artisan test --filter=DtrSampleFilesTest
```

To run the same test on the throwaway MariaDB database (never `empower`):

```
DB_CONNECTION=mysql MYSQL_DATABASE=empower_test php artisan test --filter=DtrSampleFilesTest
```

## Not covered by these files

- **A real client export.** None has been received. When CDE shares a sample
  with names removed, check it against the mapping screen.
- **Punch logs** (one row per punch). Not supported by the importer.
- **Leave and holidays.** The importer does not read them; HR records them on
  the Leave & holidays tab.
- **Grace period.** It is 0 in Settings, so a 1-minute late arrival is flagged.
  Changing it changes Carla's 20 Oct result.
