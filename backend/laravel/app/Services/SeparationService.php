<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Resignation;
use App\Models\SeparationEvent;
use App\Models\Termination;
use App\Models\TerminationClearanceItem;
use App\Models\User;
use App\Notifications\ResignationRequestActivity;
use App\Notifications\ResignationUpdate;
use App\Notifications\TerminationFinalised;
use App\Notifications\TerminationNotice;
use App\Notifications\TerminationRequestReviewed;
use App\Notifications\TerminationRequestSubmitted;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Resignation, termination, and archiving.
 *
 * A termination is two stages. HR requests it; an administrator approves,
 * returns, or rejects the request; and HR finalises an approved request only
 * once its pre-termination clearance is complete. Approval changes nothing
 * about the employee - finalisation is the one step that does.
 *
 * Separation records carry legal weight: they are the agency's evidence if a
 * dismissal is ever questioned. Nothing here is deleted, and finalising a
 * separation always writes both a status history entry and an archive snapshot
 * so the record remains readable years later even if the client company is
 * renamed or the department is retired.
 *
 * Each case also keeps its own history (separation_events). The entries marked
 * visible are what the employee's portal shows; the rest are HR's.
 *
 * Notifications are sent only after the change has committed, and only when a
 * save actually changed something, so nobody is told about a decision that
 * then rolled back, or told twice about one that was saved twice.
 */
class SeparationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ApplicantLifecycleService $lifecycle,
    ) {
    }

    // ------------------------------------------------------------ resignations

    /**
     * Files a resignation, by HR at the office or by the employee in the portal.
     *
     * Filing is the request, not the separation: the employee stays active
     * until HR completes it after clearance.
     *
     * @param  'office'|'portal'  $source
     */
    public function fileResignation(Employee $employee, array $data, User $actor, string $source = 'office'): Resignation
    {
        $portal = $source === 'portal';
        $duplicate = $portal
            ? 'You already have a resignation request in progress. You can follow it on this page.'
            : 'This employee already has a resignation in progress. Update that one instead of filing another.';

        try {
            $resignation = DB::transaction(function () use ($employee, $data, $actor, $source, $portal, $duplicate) {
                // Locked so two submissions at once - a double click, two tabs,
                // HR and the employee together - cannot both pass the checks.
                $locked = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->isActive()) {
                    throw new RuntimeException(sprintf(
                        'Only an active employee can file a resignation. This employee is %s.',
                        $locked->employment_status
                    ));
                }

                // One case at a time. Two open resignations for the same person
                // would leave HR clearing one while the other sat waiting.
                if ($locked->resignations()->whereIn('status', Resignation::OPEN_STATUSES)->exists()) {
                    throw new RuntimeException($duplicate);
                }

                $resignation = Resignation::create(array_merge($data, [
                    'employee_id' => $locked->id,
                    'source' => $source,
                    'filed_by' => $actor->id,
                    // The placement being resigned from, as it stood when filed.
                    'deployment_id' => $locked->activeDeployment()?->id,
                    'status' => 'filed',
                    'clearance_status' => 'pending',
                    // Processing is the office's work. A request from the portal
                    // has not been processed by anybody yet.
                    'processed_by' => $portal ? null : $actor->id,
                ]));

                $this->event($resignation, 'filed', $actor, null, 'filed', visible: true);

                $this->audit->record(
                    'resignation',
                    'separation',
                    Resignation::class,
                    $resignation->id,
                    null,
                    [
                        'employee_number' => $locked->employee_number,
                        'filing_date' => $resignation->filing_date?->toDateString(),
                        'requested_exit_date' => $resignation->requested_exit_date?->toDateString(),
                        'source' => $source,
                    ]
                );

                return $resignation;
            });
        } catch (UniqueConstraintViolationException) {
            // The database's own one-open-case guard, for the race the row lock
            // cannot cover on an engine without row locks.
            throw new RuntimeException($duplicate);
        }

        $resignation->setRelation('employee', $employee);

        $portal
            ? $this->notifyOffice($resignation, 'submitted')
            : $employee->notify(new ResignationUpdate($resignation, 'recorded'));

        return $resignation;
    }

    /**
     * HR's changes to an open resignation: its status, the confirmed last
     * working day, clearance, and what the employee is told.
     */
    public function updateResignation(Resignation $resignation, array $data, User $actor): Resignation
    {
        [$resignation, $kind] = DB::transaction(function () use ($resignation, $data, $actor) {
            $resignation = Resignation::query()->whereKey($resignation->getKey())->lockForUpdate()->firstOrFail();

            // A completed resignation has already ended the employment, and a
            // withdrawn one never will. Either is history, and history is not
            // edited after the fact.
            if (! $resignation->isOpen()) {
                throw new RuntimeException(sprintf(
                    'This resignation is %s and can no longer be changed.',
                    $resignation->status
                ), 409);
            }

            $from = $resignation->status;
            $to = $data['status'] ?? $from;
            $moving = $to !== $from;
            $message = array_key_exists('employee_message', $data) ? trim((string) $data['employee_message']) : null;

            if ($moving && ! in_array($to, Resignation::STAFF_TRANSITIONS[$from] ?? [], true)) {
                throw new RuntimeException(sprintf('A resignation cannot move from "%s" to "%s".', $from, $to), 422);
            }

            if ($moving && $to === 'info_requested' && blank($message)) {
                throw new RuntimeException('Write a message telling the employee what information is needed.', 422);
            }

            $exitDate = array_key_exists('exit_date', $data) ? $data['exit_date'] : $resignation->exit_date?->toDateString();

            if ($to === 'accepted' && blank($exitDate)) {
                throw new RuntimeException('Record the confirmed last working day before accepting the resignation.', 422);
            }

            if ($moving && $to === 'withdrawn' && blank($data['withdrawal_reason'] ?? null)) {
                throw new RuntimeException('Say why the resignation is being closed.', 422);
            }

            $before = $this->snapshot($resignation);

            $resignation->fill(Arr::only($data, ['reason', 'rendering_days', 'exit_date', 'clearance_status', 'remarks']));
            $resignation->status = $to;

            if ($message !== null) {
                $resignation->employee_message = $message === '' ? null : $message;
            }

            if ($moving && $to === 'withdrawn') {
                $resignation->forceFill([
                    'withdrawn_by' => $actor->id,
                    'withdrawn_at' => now(),
                    'withdrawal_reason' => $data['withdrawal_reason'],
                ]);
            }

            $after = $this->snapshot($resignation);
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            // Saved again with nothing different: no history entry, no audit
            // row, and above all no notification.
            if ($changed === []) {
                return [$resignation, null];
            }

            $resignation->processed_by = $actor->id;
            $resignation->save();

            $kind = null;

            if ($moving) {
                $kind = $to === 'filed' ? 'reopened' : $to;
                $this->event($resignation, $kind, $actor, $from, $to, visible: true,
                    note: $resignation->employee_message && in_array('employee_message', $changed, true) ? $resignation->employee_message : null,
                    details: $to === 'withdrawn' ? ['reason' => $data['withdrawal_reason']] : null);
            } elseif (in_array('employee_message', $changed, true) && filled($resignation->employee_message)) {
                $kind = 'message';
                $this->event($resignation, 'message', $actor, null, null, visible: true, note: $resignation->employee_message);
            }

            if (in_array('exit_date', $changed, true) && filled($after['exit_date'])) {
                $kind ??= 'exit_date_changed';
                $this->event($resignation, 'exit_date_changed', $actor, null, null, visible: true,
                    details: ['from' => $before['exit_date'], 'to' => $after['exit_date']]);
            }

            if (in_array('clearance_status', $changed, true)) {
                $kind ??= 'clearance_changed';
                $this->event($resignation, 'clearance_changed', $actor, null, null, visible: true,
                    details: ['from' => $before['clearance_status'], 'to' => $after['clearance_status']]);
            }

            // The office's own fields: on the case history for HR, not for the
            // employee's timeline.
            $internal = array_values(array_intersect($changed, ['reason', 'rendering_days', 'remarks']));

            if ($internal !== []) {
                $this->event($resignation, 'updated', $actor, null, null, visible: false,
                    details: ['fields' => $internal]);
            }

            $this->audit->recordUpdate(
                'separation',
                Resignation::class,
                $resignation->id,
                Arr::only($before, $changed),
                Arr::only($after, $changed),
            );

            return [$resignation, $kind];
        });

        if ($kind) {
            $resignation->employee->notify(new ResignationUpdate($resignation, $kind));
        }

        return $resignation->fresh();
    }

    /**
     * The employee takes back their own request, before HR has accepted it.
     */
    public function withdrawResignationAsEmployee(Resignation $resignation, User $actor, ?string $reason): Resignation
    {
        $resignation = DB::transaction(function () use ($resignation, $actor, $reason) {
            $resignation = Resignation::query()->whereKey($resignation->getKey())->lockForUpdate()->firstOrFail();

            if (! $resignation->canBeWithdrawnByEmployee()) {
                throw new RuntimeException($resignation->status === 'accepted'
                    ? 'HR has already accepted your resignation, so it can no longer be withdrawn here. '
                        .'Please contact the HR office if you want to stay on.'
                    : 'This request is already closed.', 409);
            }

            $from = $resignation->status;

            $resignation->forceFill([
                'status' => 'withdrawn',
                'withdrawn_by' => $actor->id,
                'withdrawn_at' => now(),
                'withdrawal_reason' => filled($reason) ? $reason : 'Withdrawn by the employee through the portal.',
            ])->save();

            $this->event($resignation, 'withdrawn', $actor, $from, 'withdrawn', visible: true, note: $reason ?: null);

            $this->audit->record('update', 'separation', Resignation::class, $resignation->id,
                ['status' => $from], ['status' => 'withdrawn', 'by' => 'employee']);

            return $resignation;
        });

        $this->notifyOffice($resignation, 'withdrawn');

        return $resignation;
    }

    /**
     * The employee answers HR's request for more information, which puts the
     * request back under review.
     */
    public function respondToInformationRequest(Resignation $resignation, User $actor, string $message): Resignation
    {
        $resignation = DB::transaction(function () use ($resignation, $actor, $message) {
            $resignation = Resignation::query()->whereKey($resignation->getKey())->lockForUpdate()->firstOrFail();

            if ($resignation->status !== 'info_requested') {
                throw new RuntimeException('HR is not waiting for information on this request.', 409);
            }

            $resignation->forceFill(['status' => 'filed'])->save();

            $this->event($resignation, 'employee_responded', $actor, 'info_requested', 'filed', visible: true, note: $message);

            $this->audit->record('update', 'separation', Resignation::class, $resignation->id,
                ['status' => 'info_requested'], ['status' => 'filed', 'by' => 'employee']);

            return $resignation;
        });

        $this->notifyOffice($resignation, 'responded');

        return $resignation;
    }

    /**
     * Completes a resignation and moves the employee out of active service.
     */
    public function completeResignation(Resignation $resignation, User $actor): Resignation
    {
        $resignation = DB::transaction(function () use ($resignation, $actor) {
            $resignation = Resignation::query()->whereKey($resignation->getKey())->lockForUpdate()->firstOrFail();

            if (! $resignation->canBeCompleted()) {
                throw new RuntimeException($resignation->status === 'info_requested'
                    ? 'HR is waiting on the employee for information. Put the request back under review or accept it before completing.'
                    : 'The employee must be cleared and have an exit date recorded before the resignation can be completed.');
            }

            $from = $resignation->status;
            $resignation->update(['status' => 'completed', 'processed_by' => $actor->id]);

            $this->event($resignation, 'completed', $actor, $from, 'completed', visible: true);

            $this->changeEmploymentStatus(
                $resignation->employee,
                'resigned',
                $actor,
                'Resignation completed on '.$resignation->exit_date->toDateString().'.',
                $resignation->exit_date->toDateString(),
            );

            $this->archive($resignation->employee, 'Resigned', $actor);

            $this->closeApplicantRecord(
                $resignation->employee,
                'resigned',
                $actor,
                'Resignation completed on '.$resignation->exit_date->toDateString().'.'
            );

            return $resignation->fresh();
        });

        // In place of the generic status notice, and only once it has committed.
        $resignation->employee->notify(new ResignationUpdate($resignation, 'completed'));

        return $resignation;
    }

    // ------------------------------------------------------------ terminations

    /**
     * HR's termination request: kept as a draft, or sent to an administrator.
     *
     * A request, never the termination itself. The employee stays active and is
     * told nothing. The administrators who review terminations are told, once
     * the request has been saved, that one is waiting for them.
     */
    public function fileTermination(Employee $employee, array $data, User $actor): Termination
    {
        $duplicate = 'A termination request for this employee is already open. Continue with that one instead of filing another.';

        try {
            $termination = DB::transaction(function () use ($employee, $data, $actor, $duplicate) {
                // Locked so two submissions at once cannot both pass the checks.
                $locked = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->isActive()) {
                    throw new RuntimeException(sprintf(
                        'Only an active employee can be terminated. This employee is %s.',
                        $locked->employment_status
                    ));
                }

                // One case at a time, for the same reason as resignations: a
                // second open termination would be reviewed while the first
                // still waited.
                if ($locked->terminations()->whereIn('status', Termination::OPEN_STATUSES)->exists()) {
                    throw new RuntimeException($duplicate);
                }

                $submitting = ($data['status'] ?? 'draft') === 'for_review';

                $termination = Termination::create(array_merge(
                    Arr::only($data, ['reason', 'termination_date', 'remarks', 'documents_path']),
                    [
                        'employee_id' => $locked->id,
                        // The placement the request concerns, as it stood when filed.
                        'deployment_id' => $locked->activeDeployment()?->id,
                        'status' => $submitting ? 'for_review' : 'draft',
                        'created_by' => $actor->id,
                        'submitted_at' => $submitting ? now() : null,
                    ]
                ));

                // HR's history only. A request is not a decision.
                $this->event($termination, 'filed', $actor, null, $termination->status, visible: false);

                $this->audit->record(
                    'termination',
                    'separation',
                    Termination::class,
                    $termination->id,
                    null,
                    [
                        'employee_number' => $locked->employee_number,
                        'reason' => $data['reason'],
                        'termination_date' => $data['termination_date'],
                        'status' => $termination->status,
                    ]
                );

                return $termination;
            });
        } catch (UniqueConstraintViolationException) {
            // The database's own one-open-case guard, for the race the row lock
            // cannot cover on an engine without row locks.
            throw new RuntimeException($duplicate);
        }

        if ($termination->status === 'for_review') {
            $this->notifyReviewers($termination, 'submitted');
        }

        return $termination;
    }

    /**
     * HR corrects a request that is still with HR: a draft, or one an
     * administrator returned for correction.
     */
    public function updateTermination(Termination $termination, array $data, User $actor): Termination
    {
        return DB::transaction(function () use ($termination, $data, $actor) {
            $termination = Termination::query()->whereKey($termination->getKey())->lockForUpdate()->firstOrFail();

            if (! $termination->isEditable()) {
                throw new RuntimeException(sprintf(
                    'This request is %s. It can be edited only as a draft or after it is returned for correction.',
                    strtolower(Termination::LABELS[$termination->status] ?? $termination->status)
                ), 409);
            }

            $fields = ['reason', 'termination_date', 'remarks', 'documents_path'];
            $before = $this->terminationSnapshot($termination);

            $termination->fill(Arr::only($data, $fields));

            $changed = array_keys(array_diff_assoc(
                array_map('strval', $this->terminationSnapshot($termination)),
                array_map('strval', $before)
            ));

            // Saved again with nothing different: no history entry, no audit row.
            if ($changed === []) {
                return $termination;
            }

            $termination->save();

            $this->event($termination, 'updated', $actor, null, null, visible: false, details: ['fields' => $changed]);

            $after = $this->terminationSnapshot($termination);
            $this->audit->recordUpdate('separation', Termination::class, $termination->id,
                Arr::only($before, $changed), Arr::only($after, $changed));

            return $termination->fresh();
        });
    }

    /**
     * Sends a draft, or a corrected request, to the administrators.
     */
    public function submitTermination(Termination $termination, User $actor): Termination
    {
        [$termination, $kind] = DB::transaction(function () use ($termination, $actor) {
            $termination = Termination::query()->whereKey($termination->getKey())->lockForUpdate()->firstOrFail();

            if (! $termination->isEditable()) {
                throw new RuntimeException(match ($termination->status) {
                    'for_review' => 'This request is already awaiting an administrator\'s review.',
                    default => sprintf(
                        'This request is %s and cannot be submitted again.',
                        strtolower(Termination::LABELS[$termination->status] ?? $termination->status)
                    ),
                }, 409);
            }

            if (! $termination->employee->isActive()) {
                throw new RuntimeException(sprintf(
                    'The employee is no longer active (%s). Withdraw this request instead.',
                    $termination->employee->employment_status
                ), 409);
            }

            $from = $termination->status;
            $kind = $from === 'returned' ? 'resubmitted' : 'submitted';

            $termination->forceFill(['status' => 'for_review', 'submitted_at' => now()])->save();

            $this->event($termination, $kind, $actor, $from, 'for_review', visible: false);

            $this->audit->record('update', 'separation', Termination::class, $termination->id,
                ['status' => $from], ['status' => 'for_review']);

            return [$termination, $kind];
        });

        $this->notifyReviewers($termination, $kind);

        return $termination;
    }

    /**
     * The administrator's decision on a request: approve it for clearance and
     * final processing, return it to HR for correction, or reject it.
     *
     * Approving terminates nobody. It hands the case back to HR with the
     * pre-termination clearance to complete, and the employee stays active,
     * and untold, until HR finalises it.
     *
     * @param  'approve'|'return'|'reject'  $decision
     */
    public function reviewTermination(Termination $termination, string $decision, ?string $note, User $actor): Termination
    {
        $to = ['approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected'][$decision]
            ?? throw new RuntimeException('Choose approve, return, or reject.', 422);
        $note = filled($note) ? trim($note) : null;

        $termination = DB::transaction(function () use ($termination, $to, $note, $actor) {
            $termination = Termination::query()->whereKey($termination->getKey())->lockForUpdate()->firstOrFail();

            // Also what makes a second click, or a second administrator acting
            // at the same moment, a refusal rather than a second decision.
            if ($termination->status !== 'for_review') {
                throw new RuntimeException(match ($termination->status) {
                    'approved', 'finalized' => 'This request has already been approved.',
                    'rejected' => 'This request has already been rejected.',
                    'returned' => 'This request was returned to HR for correction and is not awaiting review.',
                    'draft' => 'This request has not been submitted for review yet.',
                    default => 'This request was withdrawn and can no longer be reviewed.',
                }, 409);
            }

            // The decision is not made by the person who asked for it.
            if ((int) $termination->created_by === (int) $actor->id) {
                throw new RuntimeException('You filed this request, so another administrator must review it.', 403);
            }

            if ($to !== 'approved' && blank($note)) {
                throw new RuntimeException($to === 'returned'
                    ? 'Say what HR needs to correct.'
                    : 'Give the reason for rejecting the request.', 422);
            }

            if ($to === 'approved' && ! $termination->employee->isActive()) {
                throw new RuntimeException(sprintf(
                    'The employee is no longer active (%s), so there is nothing to approve. Return or reject the request.',
                    $termination->employee->employment_status
                ), 409);
            }

            $termination->forceFill(array_merge(
                [
                    'status' => $to,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                    'review_note' => $note,
                ],
                $to === 'approved' ? ['approved_by' => $actor->id, 'approved_at' => now()] : [],
            ))->save();

            if ($to === 'approved') {
                $this->createClearanceChecklist($termination);
            }

            $this->event($termination, $to, $actor, 'for_review', $to, visible: false,
                details: $note ? ['note' => $note] : null);

            $this->audit->record('update', 'separation', Termination::class, $termination->id,
                ['status' => 'for_review'], ['status' => $to, 'review_note' => $note]);

            return $termination;
        });

        $this->notifyFiler($termination->load('employee', 'creator'), $to);

        return $termination;
    }

    /**
     * Records one line of the pre-termination clearance.
     *
     * Internal to the office: nothing here is sent to the employee, who has
     * not been told of the case.
     */
    public function updateClearanceItem(Termination $termination, TerminationClearanceItem $item, array $data, User $actor): TerminationClearanceItem
    {
        return DB::transaction(function () use ($termination, $item, $data, $actor) {
            $termination = Termination::query()->whereKey($termination->getKey())->lockForUpdate()->firstOrFail();
            $item = TerminationClearanceItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $item->termination_id !== (int) $termination->id) {
                throw new RuntimeException('That clearance item belongs to another request.', 404);
            }

            if ($item->isRetired()) {
                throw new RuntimeException(sprintf(
                    '"%s" is no longer part of the clearance. It is kept on file as it was, and does not need to be completed.',
                    $item->label
                ), 409);
            }

            if ($termination->status !== 'approved') {
                throw new RuntimeException(sprintf(
                    'Clearance is recorded while an approved request awaits finalisation. This request is %s.',
                    strtolower(Termination::LABELS[$termination->status] ?? $termination->status)
                ), 409);
            }

            $status = $data['status'] ?? $item->status;
            $remarks = array_key_exists('remarks', $data) ? (trim((string) $data['remarks']) ?: null) : $item->remarks;

            if (! in_array($status, $item->allowedStatuses(), true)) {
                throw new RuntimeException(sprintf(
                    '"%s" cannot be marked %s.',
                    $item->label,
                    strtolower(TerminationClearanceItem::LABELS[$status] ?? $status)
                ), 422);
            }

            if ($status === 'not_applicable' && blank($remarks)) {
                throw new RuntimeException(sprintf('Say why "%s" does not apply to this employee.', $item->label), 422);
            }

            if ($status === 'cleared' && $item->requires_remarks && blank($remarks)) {
                throw new RuntimeException(sprintf(
                    'Record what was verified, and with whom, before marking "%s" cleared. EMPOWER holds no record of its own to show for it.',
                    $item->label
                ), 422);
            }

            $before = $item->only(['status', 'remarks']);
            $item->fill(['status' => $status, 'remarks' => $remarks]);

            if (! $item->isDirty()) {
                return $item->load('verifier');
            }

            // A resolved item names whoever last confirmed it. Moving it back
            // to an unresolved status clears the verification.
            $resolved = $item->isResolved();
            $item->forceFill([
                'updated_by' => $actor->id,
                'verified_by' => $resolved ? $actor->id : null,
                'verified_at' => $resolved ? now() : null,
            ])->save();

            $this->event($termination, 'clearance_item_updated', $actor, null, null, visible: false, details: [
                'item' => $item->item_key,
                'label' => $item->label,
                'from' => $before['status'],
                'to' => $status,
            ]);

            $this->audit->recordUpdate('separation', TerminationClearanceItem::class, $item->id,
                $before, $item->only(['status', 'remarks']));

            return $item->load('verifier');
        });
    }

    /**
     * Finalises an approved termination once its clearance is complete.
     *
     * Everything that gates it is checked again here, under a lock, whatever
     * the screen showed: the administrator's approval, every mandatory
     * clearance item, and the employee still being active. Any one failing
     * leaves everything exactly as it was.
     *
     * This is the one point at which the employee is told. The notice carries
     * the message written for them, if any; the reason and remarks stay with
     * the office.
     */
    public function finaliseTermination(Termination $termination, User $actor, ?string $employeeMessage = null): Termination
    {
        // Fixed before the notice is sent, so the termination can say which
        // notification carried it and HR can see whether it has been read.
        $noticeId = (string) Str::uuid();

        $termination = DB::transaction(function () use ($termination, $actor, $employeeMessage, $noticeId) {
            $termination = Termination::query()->whereKey($termination->getKey())->lockForUpdate()->firstOrFail();

            if ($termination->status !== 'approved') {
                throw new RuntimeException(match ($termination->status) {
                    'finalized' => 'This termination has already been finalised.',
                    'withdrawn' => 'This termination was withdrawn and can no longer be finalised.',
                    'rejected' => 'An administrator rejected this request, so it cannot be finalised.',
                    default => 'An administrator has not approved this request. Only an approved request can be finalised.',
                }, 409);
            }

            $employee = Employee::query()->whereKey($termination->employee_id)->lockForUpdate()->firstOrFail();

            if (! $employee->isActive()) {
                throw new RuntimeException(sprintf(
                    'The employee is no longer active (%s), so this termination cannot be finalised. Withdraw the request instead.',
                    $employee->employment_status
                ), 409);
            }

            $outstanding = $termination->outstandingClearance();

            if ($outstanding->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'clearance' => $outstanding->map(fn (TerminationClearanceItem $item) => sprintf(
                        '%s: %s', $item->label, strtolower($item->statusLabel())
                    ))->prepend('The clearance is not complete. Resolve every mandatory item before finalising:')->all(),
                ]);
            }

            $termination->update([
                'status' => 'finalized',
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
                // Completed before finalisation, item by item.
                'clearance_status' => 'cleared',
                'employee_message' => filled($employeeMessage) ? trim($employeeMessage) : $termination->employee_message,
                'notice_notification_id' => $noticeId,
            ]);

            $this->event($termination, 'finalised', $actor, 'approved', 'finalized', visible: true,
                note: $termination->employee_message);

            $this->audit->record('update', 'separation', Termination::class, $termination->id,
                ['status' => 'approved'], ['status' => 'finalized', 'notice_issued' => true]);

            $this->changeEmploymentStatus(
                $employee,
                'terminated',
                $actor,
                'Terminated: '.$termination->reason,
                $termination->termination_date->toDateString(),
            );

            $this->archive($employee, 'Terminated: '.$termination->reason, $actor);

            // The applicant history is what the portal's activity list reads,
            // so it records the fact and the date, not the internal reason.
            $this->closeApplicantRecord(
                $employee,
                'terminated',
                $actor,
                'Employment ended by a termination decision effective '.$termination->termination_date->toDateString().'.'
            );

            return $termination->fresh();
        });

        $notice = new TerminationNotice($termination, 'issued');
        $notice->id = $noticeId;
        $termination->employee->notify($notice);

        $termination->load('employee', 'finalizer');
        $this->users([$termination->approved_by, $termination->created_by], except: $actor)
            ->each->notify(new TerminationFinalised($termination));

        return $termination;
    }

    /**
     * Closes a termination without dismissing anybody.
     *
     * Possible at any point before it is finalised. Nothing about the employee
     * changes; the termination stays on file, marked withdrawn, with who
     * withdrew it and why, so a case that was opened and dropped is still
     * visible later. The employee was never told of the case, and is not told
     * of this either.
     */
    public function withdrawTermination(Termination $termination, string $reason, User $actor): Termination
    {
        return DB::transaction(function () use ($termination, $reason, $actor) {
            $termination = Termination::query()->whereKey($termination->getKey())->lockForUpdate()->firstOrFail();

            if (! $termination->isOpen()) {
                throw new RuntimeException(sprintf(
                    'Only an open termination request can be withdrawn. This one is %s.',
                    strtolower(Termination::LABELS[$termination->status] ?? $termination->status)
                ));
            }

            $before = $termination->status;

            $termination->update([
                'status' => 'withdrawn',
                'withdrawn_by' => $actor->id,
                'withdrawn_at' => now(),
                'withdrawal_reason' => $reason,
            ]);

            $this->event($termination, 'withdrawn', $actor, $before, 'withdrawn', visible: false, details: ['reason' => $reason]);

            $this->audit->record(
                'update',
                'separation',
                Termination::class,
                $termination->id,
                ['status' => $before],
                ['status' => 'withdrawn', 'reason' => $reason]
            );

            return $termination->fresh();
        });
    }

    /**
     * The clearance of a termination finalised under the one-step process,
     * which was recorded after the fact. A termination finalised since then
     * was cleared item by item before it was finalised, and that is not
     * reopened.
     */
    public function updateTerminationClearance(Termination $termination, string $status, User $actor): Termination
    {
        [$termination, $changed] = DB::transaction(function () use ($termination, $status, $actor) {
            $termination = Termination::query()->whereKey($termination->getKey())->lockForUpdate()->firstOrFail();

            if (! $termination->isFinalized()) {
                throw new RuntimeException('Before a termination is finalised, its clearance is recorded item by item on the request.', 409);
            }

            if (! $termination->isLegacy()) {
                throw new RuntimeException('This termination was cleared before it was finalised. Its clearance is complete.', 409);
            }

            $from = $termination->clearance_status;

            if ($from === $status) {
                return [$termination, false];
            }

            $termination->update(['clearance_status' => $status]);

            $this->event($termination, 'clearance_changed', $actor, null, null, visible: true,
                details: ['from' => $from, 'to' => $status]);

            $this->audit->recordUpdate('separation', Termination::class, $termination->id,
                ['clearance_status' => $from], ['clearance_status' => $status]);

            return [$termination, true];
        });

        if ($changed) {
            $termination->employee->notify(new TerminationNotice($termination, 'clearance_changed'));
        }

        return $termination;
    }

    /**
     * The first time the employee opens their termination notice.
     *
     * Viewing is recorded apart from acknowledging: it says the notice was in
     * front of them, not that they confirmed reading it.
     */
    public function recordNoticeViewed(Termination $termination, User $actor): void
    {
        if (! $termination->isFinalized() || $termination->notice_viewed_at) {
            return;
        }

        // Conditional, so two tabs opening it at once record one first view.
        $recorded = Termination::query()
            ->whereKey($termination->getKey())
            ->whereNull('notice_viewed_at')
            ->update(['notice_viewed_at' => now()]);

        if ($recorded) {
            $this->event($termination, 'notice_viewed', $actor, null, null, visible: true);
        }
    }

    /**
     * The employee confirms they have received and read the notice.
     *
     * Only that. It is not agreement with the decision and waives nothing, and
     * nothing waits for it: the termination took effect when it was finalised.
     */
    public function acknowledgeNotice(Termination $termination, User $actor): Termination
    {
        if (! $termination->isFinalized()) {
            throw new RuntimeException('There is no notice to acknowledge.', 404);
        }

        $recorded = Termination::query()
            ->whereKey($termination->getKey())
            ->whereNull('notice_acknowledged_at')
            ->update(['notice_acknowledged_at' => now()]);

        if ($recorded) {
            $this->recordNoticeViewed($termination->fresh(), $actor);
            $this->event($termination, 'notice_acknowledged', $actor, null, null, visible: true);
            $this->audit->record('acknowledge', 'separation', Termination::class, $termination->id,
                null, ['notice_acknowledged' => true]);
        }

        return $termination->fresh();
    }

    // --------------------------------------------------------------- archiving

    /**
     * Writes a point-in-time snapshot of the employee and their full history.
     */
    public function archive(Employee $employee, string $reason, User $actor): Archive
    {
        $employee->load([
            'applicant.educations',
            'applicant.experiences',
            'applicant.requirements.requirementType',
            'deployments.company',
            'deployments.department',
            'violations',
            'statusHistory',
        ]);

        $snapshot = [
            'employee' => $employee->only([
                'id', 'employee_number', 'biometric_number', 'hire_date',
                'current_position_title', 'employment_status',
            ]),
            'person' => $employee->applicant?->only([
                'applicant_code', 'first_name', 'middle_name', 'last_name',
                'birth_date', 'sex', 'contact_number', 'email', 'present_address',
            ]),
            'deployments' => $employee->deployments->map(fn ($d) => [
                'deployment_code' => $d->deployment_code,
                'company' => $d->company?->company_name,
                'department' => $d->department?->department_name,
                'position' => $d->position_title,
                'from' => $d->deployment_date?->toDateString(),
                'to' => $d->end_date?->toDateString(),
            ])->all(),
            'violations' => $employee->violations->map(fn ($v) => [
                'date' => $v->violation_date?->toDateString(),
                'type' => $v->violation_type,
                'description' => $v->description,
                'penalty' => $v->penalty,
                'status' => $v->status,
            ])->all(),
            'status_history' => $employee->statusHistory->map(fn ($h) => [
                'from' => $h->from_status,
                'to' => $h->to_status,
                'reason' => $h->reason,
                'at' => $h->changed_at?->toIso8601String(),
            ])->all(),
            'archived_reason' => $reason,
        ];

        $archive = Archive::create([
            'entity_type' => 'employee',
            'entity_id' => $employee->id,
            'archive_reason' => $reason,
            'snapshot_json' => $snapshot,
            'archived_by' => $actor->id,
            'archived_at' => now(),
        ]);

        $this->audit->record('update', 'archives', Archive::class, $archive->id, null, [
            'entity' => 'employee',
            'employee_number' => $employee->employee_number,
            'reason' => $reason,
        ]);

        return $archive;
    }

    // ----------------------------------------------------------------- helpers

    private function changeEmploymentStatus(
        Employee $employee,
        string $to,
        User $actor,
        string $reason,
        string $effectiveDate,
    ): void {
        $from = $employee->employment_status;
        $allowed = config("empower.employee_transitions.{$from}", []);

        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'An employee cannot move from "%s" to "%s".',
                $from,
                $to
            ));
        }

        $employee->forceFill(['employment_status' => $to])->save();

        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);

        // Close the active placement so the client company's headcount stops
        // counting someone who has left. Dated by the separation itself - the
        // exit date, or the termination date - rather than by the day somebody
        // happened to process it, so the placement history says when the person
        // actually stopped working there.
        $employee->deployments()->where('deployment_status', 'active')->update([
            'deployment_status' => 'ended',
            'end_date' => $effectiveDate,
            // Frees the one-active-deployment guard, so the person can be
            // deployed again later (audit S-04).
            'active_employee_id' => null,
        ]);
    }

    /**
     * Moves the applicant record out of employment alongside the employee.
     *
     * The applicant and the employee are the same person, and the lifecycle
     * runs from application to resignation or termination on one record. This
     * used to stop at the employee, so the applicant stayed "active" forever:
     * the applicant list showed former workers as employed, and the portal told
     * a dismissed employee they were still deployed.
     *
     * Routed through the lifecycle service so the change is validated,
     * historised and audited like every other status move. Its generic status
     * notification is not sent: the separation sends its own, written for it.
     * Skipped rather than refused if the applicant is somehow not in
     * employment, since the separation itself has already been decided and
     * recorded.
     */
    private function closeApplicantRecord(Employee $employee, string $to, User $actor, string $reason): void
    {
        $applicant = $employee->applicant;

        if (! $applicant || ! $this->lifecycle->canTransition($applicant->current_status, $to)) {
            return;
        }

        $this->lifecycle->transition($applicant, $to, $actor, $reason, [], ['notify' => false]);
    }

    private function event(
        Resignation|Termination $case,
        string $event,
        User $actor,
        ?string $from,
        ?string $to,
        bool $visible,
        ?string $note = null,
        ?array $details = null,
    ): SeparationEvent {
        return SeparationEvent::create([
            'employee_id' => $case->employee_id,
            'case_type' => $case instanceof Resignation ? 'resignation' : 'termination',
            'case_id' => $case->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'employee_note' => $note,
            'details' => $details,
            'visible_to_employee' => $visible,
            'actor_id' => $actor->id,
            'actor_role' => $actor->isPortalAccount() ? 'employee' : 'staff',
            'occurred_at' => now(),
        ]);
    }

    /**
     * The resignation's fields HR can change, as plain values to compare.
     *
     * @return array<string, string|int|null>
     */
    private function snapshot(Resignation $resignation): array
    {
        return [
            'status' => $resignation->status,
            'reason' => $resignation->reason,
            'rendering_days' => $resignation->rendering_days,
            'exit_date' => $resignation->exit_date?->toDateString(),
            'clearance_status' => $resignation->clearance_status,
            'remarks' => $resignation->remarks,
            'employee_message' => $resignation->employee_message,
        ];
    }

    /**
     * The request's fields HR can change, as plain values to compare.
     *
     * @return array<string, string|null>
     */
    private function terminationSnapshot(Termination $termination): array
    {
        return [
            'reason' => $termination->reason,
            'termination_date' => $termination->termination_date?->toDateString(),
            'remarks' => $termination->remarks,
            'documents_path' => $termination->documents_path,
        ];
    }

    /**
     * Copies the checklist in force onto an approved request, so a later
     * change to the checklist leaves this case as it was approved.
     */
    private function createClearanceChecklist(Termination $termination): void
    {
        foreach (config('empower.separation.termination_clearance', []) as $key => $definition) {
            TerminationClearanceItem::firstOrCreate(
                ['termination_id' => $termination->id, 'item_key' => $key],
                [
                    'label' => $definition['label'],
                    'kind' => $definition['kind'] ?? 'clearance',
                    'description' => $definition['description'] ?? null,
                    'is_mandatory' => (bool) ($definition['mandatory'] ?? true),
                    'requires_remarks' => (bool) ($definition['requires_remarks'] ?? false),
                    'status' => 'pending_review',
                ]
            );
        }
    }

    /**
     * The administrators who decide termination requests, told one is waiting.
     *
     * Whoever filed it is left out, since they may not decide it themselves.
     * Coordinators and portal accounts never hold the permission, so they are
     * never among the recipients; nor is the employee concerned.
     */
    private function notifyReviewers(Termination $termination, string $kind): void
    {
        $termination->loadMissing('employee.applicant', 'creator');

        User::permission('separation.review_termination')
            ->where('is_active', true)
            ->whereKeyNot($termination->created_by)
            ->get()
            ->each->notify(new TerminationRequestSubmitted($termination, $kind));
    }

    /**
     * The HR officer who filed the request, told the administrator's decision.
     * If their account is no longer active, whoever can finalise terminations
     * is told instead, so an approved case is not left waiting unseen.
     */
    private function notifyFiler(Termination $termination, string $decision): void
    {
        $recipients = $this->users([$termination->created_by]);

        if ($recipients->isEmpty()) {
            $recipients = User::permission('separation.finalize_termination')
                ->where('is_active', true)
                ->whereKeyNot($termination->reviewed_by)
                ->get();
        }

        $recipients->each->notify(new TerminationRequestReviewed($termination, $decision));
    }

    /**
     * Active staff accounts among the given ids, once each, without the person
     * who just acted - they know already.
     *
     * @param  array<int, int|null>  $ids
     */
    private function users(array $ids, ?User $except = null): Collection
    {
        return User::query()
            ->whereIn('id', array_values(array_unique(array_filter($ids))))
            ->where('is_active', true)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->get()
            ->reject(fn (User $user) => $user->isPortalAccount())
            ->values();
    }

    /**
     * Everyone who handles separations, told what the employee did.
     */
    private function notifyOffice(Resignation $resignation, string $kind): void
    {
        $resignation->loadMissing('employee.applicant');

        User::permission('separation.create')
            ->where('is_active', true)
            ->get()
            ->each->notify(new ResignationRequestActivity($resignation, $kind));
    }
}
