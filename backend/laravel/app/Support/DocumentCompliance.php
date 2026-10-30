<?php

namespace App\Support;

use App\Models\ApplicantRequirement;
use Illuminate\Support\Carbon;

/**
 * Whether a document's expiry matters for this person, at this point in their
 * employment - the one place that decides it.
 *
 * CDE, 26 October 2026: an NBI clearance only has to be valid "upon
 * employment". Once a worker is deployed it lapsing "hindi na nagmamatter" -
 * no longer matters, and is not renewed. The annual medical (with chest
 * X-ray) and the health card are different: they are renewed while the worker
 * is employed. CDE gave no fixed validity for any of them ("depende sa
 * clinic"), so the expiry date is always the one written on the document.
 *
 * So each requirement type carries a post-deployment rule:
 *
 *   pre_deployment_only   needed valid until deployment; not tracked after
 *   renew_while_employed  tracked, and renewed, for as long as they work
 *   (none)                CDE has not said. Before deployment it counts like
 *                         any other document; after deployment a lapse is
 *                         shown as "rule not confirmed", never as
 *                         non-compliance, and nothing is done about it.
 *
 * Folder filing, the daily expiry check, the dashboard and the document lists
 * all ask this class, so they cannot disagree. Nothing here changes anybody's
 * lifecycle or employment status.
 */
final class DocumentCompliance
{
    public const PRE_DEPLOYMENT_ONLY = 'pre_deployment_only';

    public const RENEW_WHILE_EMPLOYED = 'renew_while_employed';

    public const RULES = [self::PRE_DEPLOYMENT_ONLY, self::RENEW_WHILE_EMPLOYED];

    public const RULE_LABELS = [
        self::PRE_DEPLOYMENT_ONLY => 'Valid until deployment only',
        self::RENEW_WHILE_EMPLOYED => 'Renewed while employed',
        'unconfirmed' => 'After deployment: not yet confirmed by CDE',
    ];

    public const STAGE_PRE_DEPLOYMENT = 'pre_deployment';

    public const STAGE_EMPLOYED = 'employed';

    /** Separated or archived: nothing about their documents is pending. */
    public const STAGE_CLOSED = 'closed';

    /** Applicant statuses at which the person works through CDE. */
    public const EMPLOYED_STATUSES = ['deployed', 'active'];

    public const CLOSED_STATUSES = ['resigned', 'terminated', 'archived'];

    public const STATE_LABELS = [
        'not_verified' => 'Not verified',
        'no_expiry' => 'Does not expire',
        'valid' => 'Valid',
        'expiring' => 'Expiring soon',
        'expired' => 'Expired',
        'renewal_due' => 'Renewal due soon',
        'renewal_overdue' => 'Renewal overdue',
        'lapsed_after_deployment' => 'Expired after deployment - not required while employed',
        'lapsed_rule_unconfirmed' => 'Expired - renewal rule after deployment not yet confirmed by CDE',
        'not_tracked' => 'No longer tracked',
    ];

    public static function stage(?string $applicantStatus): string
    {
        return match (true) {
            in_array($applicantStatus, self::EMPLOYED_STATUSES, true) => self::STAGE_EMPLOYED,
            in_array($applicantStatus, self::CLOSED_STATUSES, true) => self::STAGE_CLOSED,
            default => self::STAGE_PRE_DEPLOYMENT,
        };
    }

    /** Whether a lapse of this kind of document counts against this person. */
    public static function expiryMatters(?string $rule, string $stage): bool
    {
        return match ($stage) {
            self::STAGE_PRE_DEPLOYMENT => true,
            self::STAGE_EMPLOYED => $rule === self::RENEW_WHILE_EMPLOYED,
            default => false,
        };
    }

    /**
     * Whether the document counts as in order for folder filing.
     *
     * Before deployment: verified and not lapsed, as it always was. After
     * deployment a lapse only counts where the document must be renewed while
     * employed - a deployed worker's NBI clearance that was valid when they
     * were placed keeps them filed as they were.
     */
    public static function countsAsComplete(ApplicantRequirement $requirement, ?string $rule, string $stage): bool
    {
        // "expired" is a verified document the daily check has since marked
        // lapsed; it was still verified, which is what matters once lapsing
        // stops counting.
        $wasVerified = $requirement->status === 'verified'
            || ($requirement->status === 'expired' && $requirement->getAttribute('verified_at') !== null);

        if (! $wasVerified) {
            return false;
        }

        if (! self::expiryMatters($rule, $stage)) {
            return true;
        }

        if ($requirement->status === 'expired') {
            return false;
        }

        return is_null($requirement->expiry_date) || ! $requirement->expiry_date->isPast();
    }

    /**
     * Where one document's expiry stands for this person today.
     *
     * @return array{state: string, label: string, needs_action: bool, days_left: int|null, rule: string, rule_label: string}
     */
    public static function assess(ApplicantRequirement $requirement, ?string $rule, string $stage, ?int $warningDays = null): array
    {
        $warningDays ??= (int) config('empower.expiry_warning_days', 30);
        $ruleKey = $rule ?? 'unconfirmed';

        $result = fn (string $state, bool $needsAction, ?int $daysLeft = null) => [
            'state' => $state,
            'label' => self::STATE_LABELS[$state],
            'needs_action' => $needsAction,
            'days_left' => $daysLeft,
            'rule' => $ruleKey,
            'rule_label' => self::RULE_LABELS[$ruleKey] ?? $ruleKey,
        ];

        $wasVerified = in_array($requirement->status, ['verified', 'expired'], true);

        if (! $wasVerified) {
            return $result('not_verified', false);
        }

        if ($requirement->expiry_date === null) {
            return $result('no_expiry', false);
        }

        $daysLeft = (int) Carbon::today()->diffInDays($requirement->expiry_date->copy()->startOfDay(), false);
        $lapsed = $daysLeft < 0 || $requirement->status === 'expired';
        $soon = ! $lapsed && $daysLeft <= $warningDays;

        if ($stage === self::STAGE_CLOSED) {
            return $result('not_tracked', false, $daysLeft);
        }

        if ($stage === self::STAGE_EMPLOYED) {
            return match (true) {
                $rule === self::RENEW_WHILE_EMPLOYED && $lapsed => $result('renewal_overdue', true, $daysLeft),
                $rule === self::RENEW_WHILE_EMPLOYED && $soon => $result('renewal_due', true, $daysLeft),
                $rule === self::PRE_DEPLOYMENT_ONLY && $lapsed => $result('lapsed_after_deployment', false, $daysLeft),
                $rule === null && $lapsed => $result('lapsed_rule_unconfirmed', false, $daysLeft),
                default => $result('valid', false, $daysLeft),
            };
        }

        return match (true) {
            $lapsed => $result('expired', true, $daysLeft),
            $soon => $result('expiring', true, $daysLeft),
            default => $result('valid', false, $daysLeft),
        };
    }
}
