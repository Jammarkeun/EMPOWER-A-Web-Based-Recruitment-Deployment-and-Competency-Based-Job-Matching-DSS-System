<?php

namespace App\Services;

use App\Exceptions\InvalidTransitionException;
use App\Models\Applicant;
use App\Models\ApplicationStatusHistory;
use App\Models\Archive;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single doorway through which an applicant's status may change.
 *
 * Controllers never write current_status directly. Routing every change through
 * here guarantees three things happen together or not at all: the transition is
 * legal, a history row is written, and an audit entry is recorded. Under the
 * spreadsheet process, status was a cell anyone could overwrite with no trace.
 */
class ApplicantLifecycleService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly FolderCategoryService $folders,
    ) {
    }

    public function transition(
        Applicant $applicant,
        string $toStatus,
        User $actor,
        ?string $reason = null,
    ): Applicant {
        $from = $applicant->current_status;

        $this->assertTransitionAllowed($from, $toStatus);
        $this->assertGatesSatisfied($applicant, $toStatus);

        $applicant = DB::transaction(function () use ($applicant, $from, $toStatus, $actor, $reason) {
            $applicant->forceFill(['current_status' => $toStatus])->save();

            ApplicationStatusHistory::create([
                'applicant_id' => $applicant->id,
                'from_status' => $from,
                'to_status' => $toStatus,
                'reason' => $reason,
                'changed_by' => $actor->id,
                'changed_at' => now(),
            ]);

            $this->audit->record(
                action: 'update',
                module: 'applicants',
                recordType: Applicant::class,
                recordId: $applicant->id,
                oldValues: ['current_status' => $from],
                newValues: ['current_status' => $toStatus, 'reason' => $reason],
            );

            if ($toStatus === 'archived') {
                Archive::firstOrCreate(
                    [
                        'entity_type' => 'applicant',
                        'entity_id' => $applicant->id,
                    ],
                    [
                        'archive_reason' => $reason,
                        'snapshot_json' => [
                            'person' => $applicant->only([
                                'applicant_code', 'first_name', 'middle_name', 'last_name',
                                'birth_date', 'sex', 'contact_number', 'email', 'present_address',
                            ]),
                            'status_history' => $applicant->statusHistory()
                                ->latest('changed_at')
                                ->get()
                                ->map(fn (ApplicationStatusHistory $history) => [
                                    'from' => $history->from_status,
                                    'to' => $history->to_status,
                                    'reason' => $history->reason,
                                    'at' => $history->changed_at?->toIso8601String(),
                                ])->all(),
                            'archived_reason' => $reason,
                        ],
                        'archived_by' => $actor->id,
                        'archived_at' => now(),
                    ]
                );
            }

            return $applicant->refresh();
        });

        // Sent after the transaction commits, not inside it: a notification for a
        // status change that then rolled back would be worse than none.
        $applicant->notify(new ApplicationStatusChanged($applicant, $toStatus));

        return $applicant;
    }

    /**
     * The document-driven section of the lifecycle, in order.
     */
    private const DOCUMENT_PATH = [
        'primary_requirements_complete',
        'pending_final_requirements',
        'ready_for_deployment',
    ];

    /**
     * Statuses from which document completeness may still advance an applicant.
     *
     * Anything beyond these has moved into client evaluation or deployment, and
     * must not be dragged backwards by a document change.
     */
    private const AUTO_ADVANCEABLE = [
        'applied',
        'initial_screening',
        'incomplete_requirements',
        'primary_requirements_complete',
        'pending_final_requirements',
    ];

    /**
     * Moves the applicant along automatically when their documents allow it.
     *
     * Called after a requirement is verified so HR does not have to remember to
     * advance someone who has just completed their paperwork - one of the main
     * reasons deployable applicants sat unnoticed in the paper system.
     *
     * The whole chain is walked rather than a single step, because an applicant
     * who submits every document in one visit should end up deployment-ready
     * immediately instead of stalling one status short.
     */
    public function syncStatusToDocuments(Applicant $applicant, User $actor): Applicant
    {
        $folder = $this->folders->recalculate($applicant);

        if (! in_array($applicant->current_status, self::AUTO_ADVANCEABLE, true)) {
            return $applicant;
        }

        $targetIndex = match ($folder) {
            'folder_1' => 2,  // ready_for_deployment
            'folder_2' => 1,  // pending_final_requirements
            default => null,
        };

        if (is_null($targetIndex)) {
            return $applicant;
        }

        // Resume from the applicant's current position so already-passed steps
        // are not re-recorded in the history.
        $position = array_search($applicant->current_status, self::DOCUMENT_PATH, true);
        $startIndex = $position === false ? 0 : $position + 1;

        for ($i = $startIndex; $i <= $targetIndex; $i++) {
            $status = self::DOCUMENT_PATH[$i];

            if (! $this->canTransition($applicant->current_status, $status)) {
                break;
            }

            $applicant = $this->transition(
                $applicant,
                $status,
                $actor,
                'Advanced automatically: document requirements satisfied.'
            );
        }

        return $applicant;
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->allowedNextStatuses($from), true);
    }

    public function allowedNextStatuses(string $from): array
    {
        return config("empower.applicant_transitions.{$from}", []);
    }

    private function assertTransitionAllowed(string $from, string $to): void
    {
        if ($from === $to) {
            throw new RuntimeException('The applicant is already in this status.');
        }

        $allowed = $this->allowedNextStatuses($from);

        if (! in_array($to, $allowed, true)) {
            throw new InvalidTransitionException($from, $to, $allowed);
        }
    }

    /**
     * Document preconditions for the statuses that carry real consequences.
     *
     * Without these an applicant could be marked ready for deployment while
     * their medical results were still outstanding, which is exactly the sort of
     * error that reaches the client site before anyone notices.
     */
    private function assertGatesSatisfied(Applicant $applicant, string $toStatus): void
    {
        /*
         * An applicant who registered online is held before screening until an
         * HR officer has confirmed their identity in person.
         *
         * The agency requires every applicant to visit the office and present
         * documents. Without this check, anyone could register from home and
         * their record would flow into screening, evaluation, and eventually a
         * client endorsement, with nobody having seen them or checked an ID.
         * Archiving stays permitted so a registration that never turns up can
         * still be closed off.
         */
        if ($applicant->awaiting_identity_check && $toStatus !== 'archived') {
            throw new RuntimeException(
                'This applicant registered online and has not yet been seen at the office. '
                .'Confirm their identity against their documents before continuing.'
            );
        }

        /*
         * Training comes before a client ever sees the candidate.
         *
         * The agency is unambiguous that every worker is trained before being
         * placed: they are called in, told to complete their documents,
         * scheduled for training, and only then taken to the client. Allowing
         * "ready for deployment" to jump straight to client evaluation let that
         * step be skipped entirely, which made the training module decorative.
         *
         * Enforced here rather than by removing the transition from the map,
         * because the map is also what the interface reads to offer the next
         * steps — and the honest answer is that this move is possible, just not
         * yet. The message says which step is missing rather than reporting an
         * illegal transition, which tells nobody anything.
         *
         * Configurable because a genuinely trained worker being redeployed
         * should not need a developer to lift the gate.
         */
        if (
            $toStatus === 'client_evaluation'
            && config('empower.require_training_before_deployment', true)
            && ! $this->hasCompletedTraining($applicant)
        ) {
            throw new RuntimeException(
                'This applicant has not completed training yet. CDE trains every worker before '
                .'endorsing them to a client, so schedule and complete their training first.'
            );
        }

        $folder = $this->folders->determine($applicant);

        match ($toStatus) {
            'primary_requirements_complete' => $this->requireFolder(
                $folder,
                ['folder_2', 'folder_1'],
                'All required primary documents must be verified first.'
            ),
            'pending_final_requirements', 'ready_for_deployment' => $this->requireFolder(
                $folder,
                $toStatus === 'ready_for_deployment' ? ['folder_1'] : ['folder_2', 'folder_1'],
                $toStatus === 'ready_for_deployment'
                    ? 'Primary and final requirements must both be verified before an applicant is deployment-ready.'
                    : 'All required primary documents must be verified first.'
            ),
            default => null,
        };
    }

    /**
     * Whether this applicant has actually been through training.
     *
     * Two ways of having done so, and both are honest. A completed enrolment is
     * the record the training module writes; already being past
     * `training_completed` in the lifecycle covers an applicant whose training
     * was recorded before this gate existed, or handled outside the module.
     * Requiring only the enrolment row would have stranded every applicant
     * already mid-process on the day this shipped.
     */
    private function hasCompletedTraining(Applicant $applicant): bool
    {
        if ($applicant->current_status === 'training_completed') {
            return true;
        }

        return $applicant->trainingEnrollments()
            ->where('completion_status', 'completed')
            ->exists();
    }

    private function requireFolder(string $actual, array $accepted, string $message): void
    {
        if (! in_array($actual, $accepted, true)) {
            throw new RuntimeException($message);
        }
    }
}
