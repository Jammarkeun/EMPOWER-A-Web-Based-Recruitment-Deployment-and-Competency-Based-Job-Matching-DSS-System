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
        private readonly PlacementService $placements,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes  Fields that belong to this move - the
     *                                            disposition of an application that stops,
     *                                            say - written in the same transaction, and
     *                                            only once the move has been validated
     */
    public function transition(
        Applicant $applicant,
        string $toStatus,
        User $actor,
        ?string $reason = null,
        array $attributes = [],
        array $context = [],
    ): Applicant {
        $this->assertTransitionAllowed($applicant->current_status, $toStatus);

        return $this->apply($applicant, $toStatus, $actor, $reason, $attributes, $context);
    }

    /**
     * A move a workflow makes on its own authority, from the statuses it names.
     *
     * The transition map is also what the applicant screen offers as buttons, so
     * a move that only a workflow may make - deploying straight from "ready for
     * deployment", or the training module withdrawing a completion it recorded
     * in error - is kept out of it. The workflow states exactly which statuses
     * it may start from; everything else (the gates, the history row, the audit
     * entry, the lock) is the same as any other move.
     *
     * @param  list<string>  $allowedFrom
     */
    public function transitionForWorkflow(
        Applicant $applicant,
        string $toStatus,
        User $actor,
        ?string $reason,
        array $allowedFrom,
        array $attributes = [],
        array $context = [],
    ): Applicant {
        $from = $applicant->current_status;

        if ($from === $toStatus) {
            throw new RuntimeException('The applicant is already in this status.');
        }

        if (! in_array($from, $allowedFrom, true)) {
            throw new InvalidTransitionException($from, $toStatus, $allowedFrom);
        }

        return $this->apply($applicant, $toStatus, $actor, $reason, $attributes, $context);
    }

    /**
     * @param  array<string, mixed>  $context  `notify` => false to skip the applicant's
     *                                         status notification (a workflow sending its
     *                                         own); `job_request_id` for an endorsement
     */
    private function apply(
        Applicant $applicant,
        string $toStatus,
        User $actor,
        ?string $reason,
        array $attributes,
        array $context,
    ): Applicant {
        $from = $applicant->current_status;

        $this->assertGatesSatisfied($applicant, $toStatus);

        $applicant = DB::transaction(function () use ($applicant, $from, $toStatus, $actor, $reason, $attributes, $context) {
            /*
             * The move was validated against the status this request loaded.
             * Locking the row and reading it again inside the transaction stops
             * two officers' simultaneous changes from both passing that check
             * and leaving a history that skips or repeats a step.
             */
            $current = Applicant::query()->whereKey($applicant->getKey())->lockForUpdate()->value('current_status');

            if ($current !== $from) {
                throw new RuntimeException(
                    'This applicant\'s status was changed by someone else a moment ago. Reload the record and try again.',
                    409
                );
            }

            $applicant->forceFill(array_merge($attributes, ['current_status' => $toStatus]))->save();

            $history = ApplicationStatusHistory::create([
                'applicant_id' => $applicant->id,
                'from_status' => $from,
                'to_status' => $toStatus,
                'reason' => $reason,
                'changed_by' => $actor->id,
                'changed_at' => now(),
            ]);

            // Opens an endorsement when the applicant is put forward to a
            // client, and closes it when they leave that stage (R-05, R-07).
            $this->placements->syncEndorsement($applicant, $from, $toStatus, $actor, $history, $context);

            $this->audit->record(
                action: 'update',
                module: 'applicants',
                recordType: Applicant::class,
                recordId: $applicant->id,
                oldValues: ['current_status' => $from],
                newValues: array_merge($attributes, ['current_status' => $toStatus, 'reason' => $reason]),
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

        /*
         * Sent only once the change has committed - including when this move is
         * one step inside a larger operation (a deployment, a training record, a
         * separation) whose own transaction is still open. A notification for a
         * change that then rolled back would be worse than none.
         */
        if ($context['notify'] ?? true) {
            DB::afterCommit(fn () => $applicant->notify(new ApplicationStatusChanged($applicant, $toStatus)));
        }

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

    /**
     * The workflow that owns a status, or null when staff may set it directly.
     */
    public function owningWorkflow(string $status): ?string
    {
        return config("empower.workflow_owned_statuses.{$status}");
    }

    /**
     * The moves to offer on the applicant record.
     *
     * Deployment stays on the list because the screen answers it with the
     * deployment form rather than a status change. Employment and separation
     * statuses do not: those are reached by recording a deployment or
     * completing a separation on the employee record, never by a button that
     * changes a word.
     */
    public function offeredNextStatuses(string $from): array
    {
        return array_values(array_filter(
            $this->allowedNextStatuses($from),
            fn (string $to) => $to === 'deployed' || $this->owningWorkflow($to) === null
        ));
    }

    /**
     * Refuses a hand-made move into a status a workflow owns.
     *
     * Without this the status endpoint could mark someone "terminated" with no
     * termination record, no administrator's review, and no confirmation — or
     * "deployed" with no employee record and no deployment behind it.
     */
    public function assertManualTransitionAllowed(string $to): void
    {
        $message = match ($this->owningWorkflow($to)) {
            'deployment' => 'Deployment is recorded with the deployment form, which creates the employee record. '
                .'It cannot be set as a status on its own.',
            'separation' => 'Employment ends through the separation process on the employee record: a resignation '
                .'is completed after clearance, and a termination is finalised by an administrator. '
                .'It cannot be set as a status on its own.',
            'training' => 'Training is recorded on the Training screen: enrol the applicant in a session, then '
                .'record their attendance and completion there. It cannot be set as a status on its own.',
            default => null,
        };

        if ($message !== null) {
            throw new RuntimeException($message, 409);
        }
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
     * Whether this applicant has actually been through training (audit R-06).
     *
     * Only a recorded completion counts: an enrolment marked completed, by an
     * officer, in a session that was not cancelled. The status word alone used
     * to count too ("training completed" set by hand on the applicant screen),
     * which let the gate pass with no training on record at all; that path is
     * closed, and training statuses now belong to the training module.
     *
     * Training completed outside EMPOWER has no way in yet. Whether CDE accepts
     * it, and on what evidence, is an open question (audit Q7); until it is
     * answered nothing here pretends a session took place.
     */
    public function hasCompletedTraining(Applicant $applicant): bool
    {
        return $applicant->trainingEnrollments()
            ->where('completion_status', 'completed')
            ->whereHas('training', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->exists();
    }

    private function requireFolder(string $actual, array $accepted, string $message): void
    {
        if (! in_array($actual, $accepted, true)) {
            throw new RuntimeException($message);
        }
    }
}
