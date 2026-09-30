# How EMPOWER Works

*A plain-language walkthrough of the system, the problem it was built for, and
the reasoning behind it.*

Prepared for consultation. Roughly eight minutes to read aloud.

---

## The agency

CDE Manpower Services is a manpower agency in Brgy. Calios, Sta. Cruz, Laguna.
It does not employ people to work in its own office; it recruits workers and
supplies them to client companies, which at present means Best Tiwi Food
Products Corporation, where **164 workers are deployed as of the latest client
validation**.

The volume matters for understanding the problem. The agency currently estimates
**twenty-five to fifty applicants** in the present month, and during a
recruitment activity run with the local PESO a large number can arrive in a
single day. Every one of them brings documents, and every one of those has to be
checked,
filed, and found again later.

## The problem

The agency's records are not in one place. Applicant records sit in physical
folders. Employee details live across Excel files and Google Sheets. Records of
workplace violations are kept apart from both. Each of these is maintained
carefully on its own, but nothing connects them.

That disconnection produces two distinct difficulties.

The first is retrieval. When a client company asks for ten production helpers,
somebody has to find out who is available and whose documents are complete. In
practice this means walking through the folders by hand. The agency has a good
system for this — applicants are filed into three folders according to how
complete their requirements are — but the folder an applicant sits in is moved
manually, and a folder moved by hand is a folder that can be misfiled. When that
happens, a qualified applicant simply becomes invisible.

The second difficulty is subtler and, we would argue, more important. The
decision about *which* applicants to send is made from the officer's memory and
judgment. That judgment is often very good, built on years of knowing what a
particular client wants. But it is never written down. So when the client asks
why these five people were sent rather than five others, or when an applicant
asks why they were not chosen, there is no record to point to. The reasoning
existed only in the moment it was made.

## What we set out to build

Our first instinct was to build a database — put the folders on a screen and
make them searchable. We decided that was not enough. A searchable database
solves retrieval, but it does nothing about the second problem: it would still
leave the actual decision undocumented.

So EMPOWER was designed as a *decision support system*. The distinction is worth
stating plainly, because it is the heart of our study. A decision support system
does not make the decision. It gathers the relevant information, applies rules
that a person has defined, presents a recommendation together with the reasoning
behind it, and then stops. The human being decides.

We also decided what the system would *not* do. Payroll is deliberately outside
our scope. The agency handles compensation through its own arrangements, and
including it would have doubled the size of the project while adding a second
domain we could not test properly. We would rather deliver one complete thing
than two half-finished ones.

## The flow

The simplest way to understand the system is to follow one manpower request and
one applicant from beginning to end.

> client raises a request → applicant registers and is screened →
> documents verified → folder derived → HR runs the evaluation →
> ranked list, with reasons → HR shortlists and deploys →
> applicant becomes an employee → separation → archive

**A client raises a request.** Best Tiwi needs, say, ten production helpers by
the end of the month. HR records the position, the number of workers, the
department, and the deadline. HR also sets the criteria that matter for this
particular role, and decides how much each one is worth. A production helper
posting might weight reliability heavily; a quality control role might weight
education and certifications instead.

**Applicants arrive.** Most walk into the office. They can now also register
online beforehand, which lets them see exactly which documents to bring — but
registering online does not replace the visit. Our client's process requires
every applicant to appear in person so staff can check their identity against
their documents, and we preserved that rule rather than designing around it. An
online registration is held aside until an officer confirms who the person is.

**Documents are collected and verified.** The agency collects eighteen
documents. Twelve are gathered at screening — birth certificate, diploma, SSS
and PhilHealth numbers, clearances, and so on. The remaining six are medical
tests, deliberately deferred until an applicant is close to being placed,
because the tests cost money and expire.

**The folder is worked out automatically.** As each document is verified, the
system recalculates which of the three folders the applicant belongs in. This is
the first of two mechanisms worth pausing on.

**HR runs the evaluation.** When the request needs filling, HR presses one
button. Every applicant far enough along to be worth considering is scored
against the criteria set for that request.

**A ranked list comes back — with reasons.** Not just a list of names and
percentages, but a line-by-line account of how each score was reached.

**HR shortlists and deploys.** A person reads the list, applies whatever
knowledge the system does not have, and decides. Recording a deployment turns
the applicant into an employee without losing any of their recruitment history,
and the request's headcount goes down by one.

**Employment runs on.** Violations, resignations, and terminations are recorded
against the same record, which eventually moves to the archive. The applicant
who walked in and the employee who left are the same file throughout.

## The two mechanisms that make it a system

If an adviser asks what makes this better than a well-organised spreadsheet,
these are the two answers.

**The folder is derived, never typed.** No one can put an applicant in Folder 1.
The system works the folder out from the documents that have actually been
verified, and recalculates it every time a document changes. This means the
digital filing cannot drift out of step with reality — which is exactly the
failure the paper system allowed. A spreadsheet cannot do this, because a
spreadsheet cell holds whatever was last typed into it.

**The sequence is enforced.** The stages an applicant passes through are defined
as a map of permitted moves: from a given stage, only certain next stages are
allowed. An applicant cannot go from "Applied" straight to "Deployed", because
that path does not exist. The document checks are not a matter of remembering to
do them; they are structurally unavoidable.

## How the matching works

HR attaches criteria to a request and gives each one a weight. The criteria come
in two kinds, and the difference between them matters.

Some are **eligibility rules** — an age range, a minimum height, a gender
requirement where a client role genuinely has one. These simply pass or fail. If
an applicant fails one, they are out. Importantly, these contribute nothing to
the score in either direction, because eligibility is not merit: being the right
age does not make someone a better worker, it only makes them a candidate.

The rest are **scored**: educational attainment, relevant work experience,
certifications, specific skills, how soon the applicant can start, distance from
the worksite, and interview ratings for communication and reliability. Each
applicant earns some portion of the points available for each criterion. Those
points are added up and compared against the maximum that was available, giving
a percentage.

The percentage falls into one of three bands. Eighty-five and above is *highly
recommended*, seventy and above is *recommended*, fifty and above goes to the
*reserve pool*. These cut-offs are settings, not fixed rules — an administrator
can change them without a programmer.

One detail we are rather pleased with: education is compared as a *ranking*
rather than an exact match. A request asking for a high school graduate will
still accept a college graduate, because higher attainment satisfies a lower
requirement. Handled naively, the stronger candidate would have been rejected
for not matching exactly.

## Why rule-based, and not artificial intelligence

This is the question we expect most, so we want to answer it directly.

The scoring engine contains no machine learning. Every point it awards traces
back to two things: a weight that a named HR officer entered, and a value
recorded on the applicant's profile. Nothing else. That is a deliberate design
decision, not a limitation we ran into.

The reason is accountability. A ranking produced by a trained model is a number
that nobody can account for. If the client asks why one applicant outranked
another, or if a rejected applicant asks the same question, "the model said so"
is not an answer. Our system can answer it line by line: this applicant scored
fifteen of fifteen on education because they are a college graduate against a
high school requirement, and ten of twenty on experience because they have
eighteen months against a range of nought to thirty-six.

There is a legal dimension too. The system holds personal and sensitive personal
information covered by the Data Privacy Act of 2012, and it is being used to
support employment decisions. Under those conditions, an unexplainable score is
a liability rather than a feature.

And so the rule we kept returning to: **the system recommends; it never hires.**
Creating a deployment is always a separate, deliberate act by a person. The
software's job is to make sure that person is well informed and that their
reasoning is written down.

## Where the project stands

The system is built and running. It works against a live PostgreSQL database
hosted on Supabase, with applicant documents held in private storage reachable
only through links that expire within minutes. There are 97 automated tests
covering the lifecycle rules, the scoring engine, the permission system, and the
isolation of the applicant portal. The source is published on GitHub.

We should be honest about one thing. A number of the business rules currently in
the system are our own reasonable defaults rather than policies CDE has
confirmed — the exact document list, how long each clearance stays valid, the
weights on each criterion, how long records should be retained. We have prepared
a checklist for the agency to confirm or correct each of them, and doing so is
the next substantial piece of work. Every one of those answers turns an
assumption in our code into a documented requirement we can cite.
