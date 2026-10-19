<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\ApplicationStatusHistory;
use App\Models\Endorsement;
use App\Models\JobRequest;
use App\Models\PlacementDecision;
use App\Models\User;
use App\Notifications\PlacementResponseRecorded;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Endorsements and the applicant's answer to each (audit R-05, R-07).
 *
 * CDE's own account of its process has the candidate decide, after training
 * and after seeing the site, whether they still want the job - and people say
 * no. That answer is about one placement. It used to be stored once, on the
 * applicant, so a decline for one client blocked every later deployment and
 * nothing could clear it. Here each answer belongs to the endorsement it
 * answers; a new endorsement asks again; and a correction is a new record that
 * names the one it corrects and why.
 *
 * Whether a decline should ever reach further than its own placement is CDE's
 * call and has not been made (audit Q1). Nothing here gives it any wider
 * effect.
 */
class PlacementService
{
    /** Statuses in which the applicant is with a client and an answer is due. */
    public const ENDORSED_STATUSES = ['client_evaluation', 'approved'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Keeps endorsements in step with the lifecycle. Called by
     * ApplicantLifecycleService inside the transaction of every status move.
     *
     * @param  array<string, mixed>  $context  `job_request_id` on an endorsement,
     *                                         `deployment_id` on a deployment
     */
    public function syncEndorsement(
        Applicant $applicant,
        string $from,
        string $to,
        User $actor,
        ApplicationStatusHistory $history,
        array $context = [],
    ): void {
        $open = $this->openEndorsement($applicant);

        // Put forward to a client. A step back from "approved" to client
        // evaluation is the same endorsement continuing, not a new one.
        if ($to === 'client_evaluation' && $from !== 'approved') {
            if (! $open) {
                Endorsement::create([
                    'applicant_id' => $applicant->id,
                    'job_request_id' => $context['job_request_id'] ?? null,
                    'status_history_id' => $history->id,
                    'endorsed_by' => $actor->id,
                    'endorsed_at' => now(),
                ]);
            }

            return;
        }

        if (! $open || in_array($to, self::ENDORSED_STATUSES, true)) {
            return;
        }

        $open->forceFill([
            'ended_at' => now(),
            'end_reason' => match ($to) {
                'deployed' => 'deployed',
                'archived' => 'archived',
                default => 'returned',
            },
            'deployment_id' => $context['deployment_id'] ?? null,
        ])->save();
    }

    public function openEndorsement(Applicant $applicant): ?Endorsement
    {
        return Endorsement::query()
            ->where('applicant_id', $applicant->id)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first();
    }

    public function currentDecision(?Endorsement $endorsement): ?PlacementDecision
    {
        return $endorsement?->decisions()->reorder()->orderByDesc('id')->first();
    }

    /** Whether the applicant has an offer in front of them that they have not answered. */
    public function decisionDue(Applicant $applicant): bool
    {
        if (! in_array($applicant->current_status, self::ENDORSED_STATUSES, true)) {
            return false;
        }

        return $this->currentDecision($this->openEndorsement($applicant)) === null;
    }

    /**
     * The applicant answers on their own portal. Once only per endorsement:
     * a change of mind goes through the office, which records it as a
     * correction with the reason.
     */
    public function recordApplicantDecision(Applicant $applicant, User $user, string $decision, ?string $note): PlacementDecision
    {
        $placement = DB::transaction(function () use ($applicant, $user, $decision, $note) {
            $applicant = $this->lockEndorsedApplicant($applicant, 'There is no placement waiting for your answer at the moment.');
            $endorsement = $this->endorsementFor($applicant);

            if ($this->currentDecision($endorsement)) {
                throw new RuntimeException(
                    'You have already answered for this placement. Please contact the office if you have changed your mind.',
                    409
                );
            }

            return $this->write($applicant, $endorsement, [
                'decision' => $decision,
                'note' => $note,
                'source' => 'applicant',
                'recorded_by' => $user->id,
            ]);
        });

        // The office needs to know, and quickly: a decline means the client is
        // expecting somebody who is not coming.
        DB::afterCommit(fn () => User::query()
            ->whereIn('user_type', ['admin', 'hr'])
            ->where('is_active', true)
            ->get()
            ->each->notify(new PlacementResponseRecorded($applicant, $decision, $note)));

        return $placement;
    }

    /**
     * HR corrects the answer on file - typically because the applicant has
     * changed their mind and told the office.
     *
     * Only a correction: the first answer is the applicant's to give, on their
     * portal, as it always has been. Whether the office may also record a
     * first answer given by phone or at the counter is for CDE to decide, and
     * is not assumed here.
     *
     * A correction needs a reason and is stored as a new answer pointing at
     * the one it replaces; the original is never changed.
     */
    public function recordStaffDecision(
        Applicant $applicant,
        User $staff,
        string $decision,
        ?string $note,
        ?string $correctionReason,
    ): PlacementDecision {
        return DB::transaction(function () use ($applicant, $staff, $decision, $note, $correctionReason) {
            $applicant = $this->lockEndorsedApplicant(
                $applicant,
                'This applicant is not with a client at the moment, so there is no placement to answer for.'
            );
            $current = $this->currentDecision($this->openEndorsement($applicant));

            if (! $current) {
                throw new RuntimeException(
                    'The applicant has not answered for this placement yet. The first answer is theirs to give, '
                    .'on their portal; once it is recorded, the office can correct it.',
                    409
                );
            }

            if (blank($correctionReason)) {
                throw ValidationException::withMessages([
                    'correction_reason' => ['Give the reason for correcting the answer on record.'],
                ]);
            }

            if ($current->decision === $decision) {
                throw ValidationException::withMessages([
                    'decision' => ['That is already the answer on record for this placement.'],
                ]);
            }

            return $this->write($applicant, $current->endorsement, [
                'decision' => $decision,
                'note' => $note,
                'source' => 'staff',
                'recorded_by' => $staff->id,
                'corrects_decision_id' => $current->id,
                'correction_reason' => $correctionReason,
            ]);
        });
    }

    /**
     * The answer that stops this deployment, if there is one.
     *
     * Only the current endorsement's answer counts, and only when that
     * endorsement is for this request or names none. A decline for another
     * client's request does not stand in the way of this one.
     */
    public function decliningDecision(Applicant $applicant, JobRequest $jobRequest): ?PlacementDecision
    {
        $endorsement = $this->openEndorsement($applicant);

        if (! $endorsement || ($endorsement->job_request_id && (int) $endorsement->job_request_id !== (int) $jobRequest->id)) {
            return null;
        }

        $decision = $this->currentDecision($endorsement);

        return $decision?->isDecline() ? $decision : null;
    }

    /**
     * The placement picture for the applicant screen: the endorsement in
     * progress, every answer and correction to it, and whether the client's
     * approval has been recorded.
     */
    public function present(Applicant $applicant): array
    {
        $endorsement = Endorsement::query()
            ->with(['jobRequest.company', 'endorser', 'decisions.recorder'])
            ->where('applicant_id', $applicant->id)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first();

        $approval = null;

        if ($endorsement && $applicant->current_status === 'approved') {
            $row = ApplicationStatusHistory::with('changedBy')
                ->where('applicant_id', $applicant->id)
                ->where('to_status', 'approved')
                ->when($endorsement->endorsed_at, fn ($q, $at) => $q->where('changed_at', '>=', $at))
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->first();

            $approval = $row ? [
                'recorded_by' => $row->changedBy?->full_name,
                'recorded_at' => $row->changed_at?->toIso8601String(),
                'note' => $row->reason,
            ] : null;
        }

        $decisions = $endorsement?->decisions ?? collect();
        $current = $decisions->last();

        return [
            'decision_due' => in_array($applicant->current_status, self::ENDORSED_STATUSES, true) && $current === null,
            'endorsement' => $endorsement ? [
                'id' => $endorsement->id,
                'endorsed_at' => $endorsement->endorsed_at?->toIso8601String(),
                'endorsed_by' => $endorsement->endorser?->full_name,
                'recorded_retroactively' => $endorsement->recorded_retroactively,
                'note' => $endorsement->note,
                'job_request' => $endorsement->jobRequest ? [
                    'id' => $endorsement->jobRequest->id,
                    'request_code' => $endorsement->jobRequest->request_code,
                    'position_title' => $endorsement->jobRequest->position_title,
                    'company_name' => $endorsement->jobRequest->company?->company_name,
                ] : null,
            ] : null,
            'current_decision' => $current ? $this->presentDecision($current) : null,
            'decisions' => $decisions->map(fn ($d) => $this->presentDecision($d))->values(),
            'client_approval' => $approval,
            'approval_required_before_deployment' => (bool) config('empower.require_client_approval_before_deployment', false),
        ];
    }

    public function presentDecision(PlacementDecision $decision): array
    {
        return [
            'id' => $decision->id,
            'decision' => $decision->decision,
            'note' => $decision->note,
            'source' => $decision->source,
            'recorded_by' => $decision->recorder?->full_name,
            'decided_at' => $decision->decided_at?->toIso8601String(),
            'corrects_decision_id' => $decision->corrects_decision_id,
            'correction_reason' => $decision->correction_reason,
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function lockEndorsedApplicant(Applicant $applicant, string $notEndorsedMessage): Applicant
    {
        $fresh = Applicant::query()->whereKey($applicant->getKey())->lockForUpdate()->firstOrFail();

        if (! in_array($fresh->current_status, self::ENDORSED_STATUSES, true)) {
            throw new RuntimeException($notEndorsedMessage, 409);
        }

        return $fresh;
    }

    /**
     * The endorsement an answer belongs to.
     *
     * Normally the one opened when the applicant moved into client evaluation.
     * Records put into that status without going through it - older data, or
     * a status set directly - have none; one is opened now and marked as
     * recorded retroactively, so it is never mistaken for an endorsement
     * somebody recorded at the time.
     */
    private function endorsementFor(Applicant $applicant): Endorsement
    {
        $open = $this->openEndorsement($applicant);

        if ($open) {
            return $open;
        }

        $history = ApplicationStatusHistory::query()
            ->where('applicant_id', $applicant->id)
            ->where('to_status', 'client_evaluation')
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        return Endorsement::create([
            'applicant_id' => $applicant->id,
            'status_history_id' => $history?->id,
            'endorsed_by' => $history?->changed_by,
            'endorsed_at' => $history?->changed_at,
            'recorded_retroactively' => true,
            'note' => 'Opened when the first answer was recorded; the endorsement itself was not recorded at the time.',
        ]);
    }

    private function write(Applicant $applicant, Endorsement $endorsement, array $values): PlacementDecision
    {
        $decision = PlacementDecision::create($values + [
            'applicant_id' => $applicant->id,
            'endorsement_id' => $endorsement->id,
            'decided_at' => now(),
        ]);

        $this->audit->record(
            action: $decision->corrects_decision_id ? 'correction' : 'create',
            module: 'placement',
            recordType: PlacementDecision::class,
            recordId: $decision->id,
            oldValues: $decision->corrects_decision_id ? ['corrects_decision_id' => $decision->corrects_decision_id] : null,
            newValues: [
                'applicant_code' => $applicant->applicant_code,
                'endorsement_id' => $endorsement->id,
                'decision' => $decision->decision,
                'source' => $decision->source,
                'correction_reason' => $decision->correction_reason,
            ],
        );

        return $decision;
    }
}
