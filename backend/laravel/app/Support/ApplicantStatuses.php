<?php

namespace App\Support;

/**
 * The applicant-status vocabulary, in one place (audit CQ-02, UX-08).
 *
 * The statuses themselves and the moves between them are policy and live in
 * config/empower.php. This class answers the presentation questions about them
 * - what a status is called, which stage of the journey it belongs to, whether
 * it closes the record - so the staff screens, the portal and the published
 * vocabulary cannot drift apart. The applicant list used to keep its own copy
 * of the status list, and the staff progress path grouped "ready for
 * deployment" after training, showing training as done for people who had not
 * been trained.
 */
final class ApplicantStatuses
{
    /**
     * The journey in the six stages people talk about, in lifecycle order.
     *
     * The order must follow config/empower.php's transition map: a forward
     * move never lands in an earlier stage (ApplicantStatusVocabularyTest
     * checks this). "Ready for deployment" opens the training stage because
     * training comes next - CDE trains every worker before endorsing them.
     */
    public const STAGES = [
        'application' => ['label' => 'Application', 'statuses' => ['applied']],
        'screening' => ['label' => 'Screening', 'statuses' => ['initial_screening']],
        'requirements' => [
            'label' => 'Requirements',
            'statuses' => ['incomplete_requirements', 'primary_requirements_complete', 'pending_final_requirements'],
        ],
        'training' => [
            'label' => 'Training',
            'statuses' => ['ready_for_deployment', 'training_scheduled', 'training_completed'],
        ],
        'client' => ['label' => 'Client evaluation', 'statuses' => ['client_evaluation', 'approved']],
        'deployed' => ['label' => 'Deployed', 'statuses' => ['deployed', 'active']],
    ];

    /** Statuses that close the record rather than sit on the path. */
    public const CLOSED = ['resigned', 'terminated', 'archived'];

    /** The statuses a deployment can ever be recorded from (DeploymentService). */
    public const DEPLOYABLE_FROM = ['approved', 'ready_for_deployment'];

    /**
     * Those statuses as Settings narrow them today: with the client's approval
     * required before deployment, only "approved". Published so a screen offers
     * Deploy exactly when the server will accept it.
     */
    public static function deployable(): array
    {
        return config('empower.require_client_approval_before_deployment', false)
            ? ['approved']
            : self::DEPLOYABLE_FROM;
    }

    /** Every status, in the order the lifecycle defines them. */
    public static function all(): array
    {
        return array_keys(config('empower.applicant_transitions', []));
    }

    public static function isKnown(?string $status): bool
    {
        return $status !== null && in_array($status, self::all(), true);
    }

    /**
     * The wording every screen shows for a status.
     *
     * Kept as the status itself in words ("Pending Final Requirements") because
     * that is the vocabulary CDE's staff already use; the stage gives the
     * plain-language grouping beside it.
     */
    public static function label(?string $status): string
    {
        return $status ? ucwords(str_replace('_', ' ', $status)) : '';
    }

    public static function stageOf(string $status): ?string
    {
        foreach (self::STAGES as $key => $stage) {
            if (in_array($status, $stage['statuses'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** 1-based position on the path, or null for a closed record. */
    public static function stageNumber(string $status): ?int
    {
        $key = self::stageOf($status);

        return $key === null ? null : array_search($key, array_keys(self::STAGES), true) + 1;
    }

    public static function isClosed(string $status): bool
    {
        return in_array($status, self::CLOSED, true);
    }

    public static function isPastDocumentGate(string $status): bool
    {
        return in_array($status, config('empower.past_document_gate_statuses', []), true);
    }

    /**
     * Whether the status claims more paperwork than the folder now shows
     * (audit WF-06): reached with Folder 1, no longer in it.
     */
    public static function documentsLapsed(string $status, ?string $folder): bool
    {
        return self::isPastDocumentGate($status) && $folder !== 'folder_1';
    }

    /** The published vocabulary, for screens that list or group statuses. */
    public static function describe(): array
    {
        return [
            'statuses' => array_map(fn (string $status) => [
                'value' => $status,
                'label' => self::label($status),
                'stage' => self::stageOf($status),
                'closed' => self::isClosed($status),
                'past_document_gate' => self::isPastDocumentGate($status),
                'workflow' => config("empower.workflow_owned_statuses.{$status}"),
            ], self::all()),
            'stages' => self::stages(),
            'deployable' => self::deployable(),
        ];
    }

    public static function stages(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, 'label' => self::STAGES[$key]['label'], 'statuses' => self::STAGES[$key]['statuses']],
            array_keys(self::STAGES)
        );
    }
}
