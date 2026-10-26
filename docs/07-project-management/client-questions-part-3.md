# Client Questions — Parts 14 to 22

**EMPOWER: A Web-Based Recruitment, Deployment, and Competency-Based Job Matching
Decision Support System for CDE Manpower Services**
Laguna State Polytechnic University — College of Computer Studies
Researchers: Cabuhat, John Mark D. | Mejorada, Daniel Matthew B. | Gelba, Justine Benedict L.

---

## How to use this document

The system is now built and tested. While finishing it, we reached a number of
points where the system has to follow a rule that only CDE can give — so for
each one we chose a careful temporary rule and marked it as ours, not CDE's.
These questions are what we need to replace those temporary rules with CDE's
real practice, and to prepare the system for the final defense and for use at
the office.

Like the earlier checklist, every question says **what the system does now**,
so there is something concrete to confirm or correct.

- **[BLOCKING]** — we cannot finish the system or the paper without an answer.
- **[IMPORTANT]** — an answer materially improves the system.
- **[OPTIONAL]** — useful, drop it if time is short.

There are 39 questions, of which 12 are blocking. If the meeting is short, ask
the blocking ones first; the rest can follow by email or Messenger.

*Notes to ourselves before sending:*
- *Remove any question already answered in the follow-up questionnaire with
  Mr. Domingo; those answers are not in the repository, so we could not check.*
- *The answer type in `[brackets]` is the Google Form field to use. Remove the
  brackets before sending.*

---

## Part 14. The Client's Decision and the Placement

**14.1 [BLOCKING]** Before CDE deploys a worker, is the client company's
**approval** always given first — for example after it assesses the worker
following training? At present the system lets HR record a deployment straight
from "ready for deployment", and notes in the history that the client's approval
was not recorded separately. If approval always comes first, the system can
require it.
`[Multiple choice: Client always approves first / Sometimes / CDE decides without the client / Other:______]`

**14.2 [BLOCKING]** When CDE puts forward a shortlist of candidates for a
request, is that the same moment the applicants are **endorsed** to the client,
or are those two separate steps? At present they are two separate actions in the
system.
`[Multiple choice: Same step / Separate steps / Other:______]`

**14.3 [IMPORTANT]** After training, the client assesses the candidate, CDE
assesses the candidate, and the candidate decides whether they want the job. At
present only the **candidate's** answer is recorded on its own. Should the
**client's assessment** be recorded too — as a pass/fail, a score, a form, or
the name of the person who assessed?
`[Long answer]`

**14.4 [IMPORTANT]** And **CDE's own assessment** after training — what is it
based on, and should it be recorded?
`[Long answer]`

**14.5 [IMPORTANT]** When an applicant **declines** a placement, the system
stops that one placement only; the applicant can still be endorsed to another
client later, and the office can record or correct an answer given by phone,
with a reason. Is that how CDE treats a decline?
`[Multiple choice: Correct / Needs correction:______]`

**14.6 [BLOCKING]** Applicants are brought to the client with an **endorsement
letter**. Could CDE share a blank or old copy? We need to know who signs it,
whether it has a reference number, and whether the system should produce it.
`[File upload or Long answer]`

**14.7 [IMPORTANT]** Could CDE share a blank copy of the client's **deployment
details form** (department, start date, supervisor, employee and biometrics
numbers)? We want the system's deployment form to match it field for field.
`[File upload]`

---

## Part 15. Training

**15.1 [BLOCKING]** When training is held **at the client's site** with the
client's coordinator, how does CDE confirm who attended and who finished — an
attendance sheet, a call from the coordinator, a certificate? At present an
officer marks each trainee present and completed, and the system records which
officer did it, but not how they knew. Should the system also record the proof
(for example "attendance sheet signed by the coordinator", or an uploaded copy)?
`[Long answer]`

**15.2 [IMPORTANT]** The system requires a recorded completed training before an
applicant can be endorsed to a client. Is training required for **every**
position and every client, or are there exceptions?
`[Multiple choice: Every position / Some positions only:______ / Other:______]`

**15.3 [IMPORTANT]** Does a completed training **expire**? For example, if an
applicant is not deployed within some months, do they need to be trained again?
At present a completion counts indefinitely.
`[Short answer]`

**15.4 [OPTIONAL]** Is training ever done in more than one session per person,
or with a test at the end that the applicant can fail?
`[Long answer]`

---

## Part 16. After Deployment: Moves, Separations, and Returning Workers

**16.1 [IMPORTANT]** When a worker is moved to **another department or position
at the same client**, who decides, and how does CDE find out? At present HR
records the move with the date and a reason, and the system keeps the earlier
placement in the history. It does not accept a date in the future — the move is
recorded once it has happened. Is that right?
`[Long answer]`

**16.2 [IMPORTANT]** When a worker moves to a **different client company**, the
system records it as a new deployment against that client's own request, so each
request's headcount stays correct. Is that how CDE sees it?
`[Multiple choice: Correct / Needs correction:______]`

**16.3 [BLOCKING]** Can a **former employee** be hired and deployed again? At
present the system cannot do this at all. If yes: does it matter whether they
resigned or were terminated, is there a waiting period, and should their old
record (with its history and employee number) be reopened?
`[Long answer]`

**16.4 [IMPORTANT]** When a worker resigns or is terminated, does their place on
the client's request **open up again**, or does the client send a new request
for a replacement? At present nothing reopens automatically.
`[Multiple choice: The slot reopens / The client sends a new request / Depends:______]`

**16.5 [IMPORTANT]** If an employee **resigns while a termination is being
reviewed**, what happens to the termination case?
`[Long answer]`

**16.6 [IMPORTANT]** May the **same administrator** who files a termination also
give it final approval, or should a second person approve it? At present one
administrator may do both.
`[Multiple choice: Same person may / Must be a different person / Different person when one is available]`

---

## Part 17. Manpower Requests and Client Companies

**17.1 [IMPORTANT]** Who may **close, cancel, or reopen** a client's manpower
request? At present any HR officer may, with a written reason, and a cancelled
request can be reopened. Should a cancelled request stay cancelled?
`[Long answer]`

**17.2 [IMPORTANT]** When CDE's contract with a client **ends**, what should
happen to that client's open requests, its deployed workers, and the coordinator
assigned to it? At present only the client's status changes.
`[Long answer]`

---

## Part 18. Documents and Applicant Status

**18.1 [IMPORTANT]** If a document **expires or is rejected** after an applicant
already reached "ready for deployment" (or a later stage), what should happen?
At present the status stays the same, the system shows a "Documents lapsed"
warning beside it, and deployment is refused until the document is renewed.
`[Multiple choice: Keep the status and warn (as now) / Move them back to incomplete requirements / Move them back only if not yet endorsed to a client / Other:______]`

**18.2 [OPTIONAL]** Requirements like **lifting 25–50 kg** or **standing for six
hours** are stored as notes on the request, not scored. Should the system
record and check them for each applicant?
`[Multiple choice: Yes, record and check / Keep as notes / Other:______]`

**18.3 [OPTIONAL]** Walk-in applicants sign a **physical application log
sheet**. Does CDE still need that sheet, or can the system's records replace it?
`[Multiple choice: Still needed / The system can replace it / Not sure]`

---

## Part 19. Keeping Records (Follow-up on Retention)

CDE told us that unhired applicants are kept about **three months** "depending
on the situation", and legal documents **five to ten years**. The system now
lists records that pass those periods for a person to review; it deletes
nothing on its own. We need a few details to make that list correct.

**19.1 [BLOCKING]** For an **unhired applicant**, the three months count from
when? At present they count from the last activity on the application (applying,
a stage change, or a document being added or checked).
`[Multiple choice: From the application date / From the last activity (as now) / From the last contact with the applicant / Other:______]`

**19.2 [BLOCKING]** For **legal documents**, the five to ten years count from
when, and which documents does that cover? At present the period counts from the
day the record closed (resigned, terminated, or archived).
`[Long answer]`

**19.3 [BLOCKING]** When a record's period has passed and someone reviews it,
what does CDE actually do with it — keep it longer, archive it, return the
papers, shred them, delete the digital copy? And who must approve that? At
present the system offers only "keep on file until a date" and "archive"; it
offers no deletion, because we have no procedure from CDE for it.
`[Long answer]`

**19.4 [IMPORTANT]** Who at CDE should do these retention reviews?
`[Short answer]`

**19.5 [IMPORTANT]** If an applicant asks CDE to **delete** their personal
information, what should happen? Does CDE have a designated Data Protection
Officer? *(Skip if already answered in the follow-up.)*
`[Long answer]`

---

## Part 20. Accounts, Passwords, and Contact Details

**20.1 [BLOCKING]** What **office address and contact number** should the system
show — on the "Forgot your password?" page, in the applicant portal, and on the
report letterhead? At present it shows only "Sta. Cruz, Laguna", with no
number.
`[Short answer]`

**20.2 [IMPORTANT]** At present someone who forgets their password asks the
office, which issues a **temporary password** that must be changed at the next
sign-in. The system can also send a reset link by email, but only if CDE has an
**email account the system may send from**. Is there one, and do most
applicants have an email address they actually check?
`[Long answer]`

**20.3 [IMPORTANT]** Who at the office may reset a password, and how should they
confirm who is asking — in person with an ID, by phone, or either?
`[Long answer]`

**20.4 [IMPORTANT]** Are any office computers **shared** between staff? At
present a staff member stays signed in until they sign out. Should the system
sign people out after a period of no use — for example after an hour — or at
the end of the working day?
`[Multiple choice: After an hour idle / End of the working day / Leave as is / Other:______]`

**20.5 [OPTIONAL]** Would it help if applicants received a **text or email** at
key moments — a document rejected, a placement offered? (This costs money per
message, so we will only add it if CDE wants it.)
`[Multiple choice: Yes, text / Yes, email / Both / No]`

---

## Part 21. Attendance (DTR)

**21.1 [IMPORTANT]** Can the client's biometric system **export** its DTR — for
example as an Excel or CSV file? If so, could CDE share a sample file with names
removed? At present HR types the DTR into the system by hand.
`[File upload or Long answer]`

**21.2 [IMPORTANT]** Is there a **grace period** before a worker counts as late
(for example 5 or 15 minutes)? At present lateness counts from the first minute.
`[Short answer]`

**21.3 [OPTIONAL]** How is **overtime** approved, and should the system show it?
At present time after the scheduled end is shown as "past scheduled end", not as
approved overtime. Pay is never computed.
`[Long answer]`

---

## Part 22. Going Live and the Defense

**22.1 [BLOCKING]** For the final defense, may we use **real CDE records** (for
example the current deployed employees), or sample data only? If real records
are allowed, what consent or approval does CDE need from us first?
`[Long answer]`   

**22.2 [BLOCKING]** After the defense, does CDE intend to **keep using** the
system? If so, who will be its administrator, and who will be responsible for
the online hosting account (Supabase) and any cost after the free tier?
`[Long answer]`

**22.3 [BLOCKING]** When can CDE staff do the **hands-on evaluation**, and how
many people can take part? *(Skip if dates were already agreed.)*
`[Long answer]`

**22.4 [IMPORTANT]** Which staff should have accounts at go-live, and in which
role — Administrator, HR, or Coordinator (one per client company)?
`[Long answer]`

---

*Thank you. If any item is confidential, please mark it so and we will leave it
out of the paper entirely.*
