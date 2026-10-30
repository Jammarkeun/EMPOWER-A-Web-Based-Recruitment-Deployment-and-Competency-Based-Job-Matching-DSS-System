<?php

namespace App\Console\Commands;

use App\Models\Applicant;
use App\Models\ApplicationStatusHistory;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Endorsement;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ApplicantStatuses;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings applicant statuses back in line with the employment record
 * (filter audit, 2026-10-30).
 *
 * Deployment moves the applicant to "active" in the transaction that records
 * it, and no other route may set "deployed" or "active"
 * (empower.workflow_owned_statuses). Records changed before those rules, or
 * edited directly in the database, can still disagree. Two shapes are
 * corrected, each from evidence already in the database:
 *
 * - Placed but not marked: an active employee with an active deployment
 *   whose applicant status was put back to an earlier stage. The employment
 *   record says they work, so the status becomes "active", and an
 *   endorsement left open by the walk back is closed as "deployed".
 * - Marked but never placed: "deployed" or "active" with no employee record,
 *   reached by hand through the old status endpoint. Nobody was deployed,
 *   so the status returns to the one held before that move, as the history
 *   records it.
 *
 * Anything less clear - a separated status beside a live placement, a
 * deleted employee record, an employee with no active placement, a history
 * that does not show the earlier status - is listed for a person to decide
 * and never changed.
 *
 * A dry run unless --apply is given. Each correction writes one status-history
 * row dated now, under the account named by --actor, with a reason saying what
 * was corrected and why, and an audit entry. Nothing is back-dated and no
 * notification is sent.
 */
class ReconcilePlacementStatus extends Command
{
    protected $signature = 'empower:reconcile-placement-status
        {--apply : Write the corrections; without it nothing is changed}
        {--actor= : Email of the HR or administrator account to record the corrections under (required with --apply)}';

    protected $description = 'List, and with --apply correct, applicant statuses that disagree with the employment record';

    private const EMPLOYED = ['deployed', 'active'];

    public function handle(AuditService $audit): int
    {
        $actor = null;

        if ($this->option('apply')) {
            $actor = User::query()->where('email', (string) $this->option('actor'))->first();

            if (! $actor || ! $actor->isStaff() || ! $actor->is_active) {
                $this->error('--apply needs --actor=<email> of an active HR or administrator account.');

                return self::FAILURE;
            }
        }

        [$corrections, $reviews] = $this->findings();

        if ($corrections === [] && $reviews === []) {
            $this->info('Every applicant status agrees with the employment record.');

            return self::SUCCESS;
        }

        if ($corrections !== []) {
            $this->line($actor ? 'Correcting:' : 'Would correct (dry run; pass --apply --actor=<email> to write):');
            $this->table(
                ['Applicant', 'Name', 'Status', 'Becomes', 'Why'],
                array_map(fn ($c) => [
                    $c['applicant']->applicant_code,
                    $c['applicant']->full_name,
                    ApplicantStatuses::label($c['from']),
                    ApplicantStatuses::label($c['to']),
                    $c['reason'],
                ], $corrections),
            );
        }

        if ($reviews !== []) {
            $this->warn('Left for a person to decide (never changed here):');
            $this->table(
                ['Applicant', 'Name', 'Status', 'Why'],
                array_map(fn ($r) => [
                    $r['applicant']->applicant_code,
                    $r['applicant']->full_name,
                    ApplicantStatuses::label($r['applicant']->current_status),
                    $r['note'],
                ], $reviews),
            );
        }

        if (! $actor) {
            return self::SUCCESS;
        }

        $applied = 0;

        foreach ($corrections as $correction) {
            $applied += $this->apply($correction, $actor, $audit) ? 1 : 0;
        }

        $this->info("{$applied} of ".count($corrections).' correction(s) written.');

        return $applied === count($corrections) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: list<array>, 1: list<array>} */
    private function findings(): array
    {
        $corrections = [];
        $reviews = [];

        // Working under an active deployment, whatever the status says.
        $placed = Applicant::query()
            ->currentlyDeployed()
            ->where('current_status', '!=', 'active')
            ->orderBy('id')
            ->get();

        foreach ($placed as $applicant) {
            $employee = Employee::query()->where('applicant_id', $applicant->id)->active()->first();
            $deployment = Deployment::query()->where('employee_id', $employee->id)->active()->orderByDesc('id')->first();

            if (ApplicantStatuses::isClosed($applicant->current_status)) {
                $reviews[] = ['applicant' => $applicant, 'note' => sprintf(
                    'Status is %s, but employee %s is still active under %s. Which one is wrong needs a person.',
                    ApplicantStatuses::label($applicant->current_status),
                    $employee->employee_number,
                    $deployment->deployment_code,
                )];

                continue;
            }

            $corrections[] = [
                'applicant' => $applicant,
                'from' => $applicant->current_status,
                'to' => 'active',
                'deployment' => $deployment,
                'reason' => sprintf(
                    'Corrected to match the employment record: employee %s has been deployed under %s since %s. '
                    .'The status had been changed outside the deployment workflow.',
                    $employee->employee_number,
                    $deployment->deployment_code,
                    $deployment->deployment_date?->toDateString(),
                ),
            ];
        }

        // Marked as employed without an employee record.
        $unplaced = Applicant::query()
            ->whereIn('current_status', self::EMPLOYED)
            ->whereDoesntHave('employee')
            ->orderBy('id')
            ->get();

        foreach ($unplaced as $applicant) {
            if (Employee::withTrashed()->where('applicant_id', $applicant->id)->exists()) {
                $reviews[] = ['applicant' => $applicant, 'note' => 'Their employee record was deleted; whether they still work for CDE needs a person.'];

                continue;
            }

            $move = ApplicationStatusHistory::query()
                ->where('applicant_id', $applicant->id)
                ->where('to_status', 'deployed')
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->first();

            if (! $move || ! in_array($move->from_status, ApplicantStatuses::DEPLOYABLE_FROM, true)) {
                $reviews[] = ['applicant' => $applicant, 'note' => 'No employee record, and the history does not show the status before the move to Deployed.'];

                continue;
            }

            $corrections[] = [
                'applicant' => $applicant,
                'from' => $applicant->current_status,
                'to' => $move->from_status,
                'deployment' => null,
                'reason' => sprintf(
                    'Corrected: moved to Deployed on %s without a deployment being recorded, and there is no '
                    .'employee record. Returned to %s, the status before that move.',
                    $move->changed_at?->toDateString(),
                    ApplicantStatuses::label($move->from_status),
                ),
            ];
        }

        // An employee who is active on paper with nowhere to work.
        $idle = Applicant::query()
            ->whereIn('current_status', self::EMPLOYED)
            ->whereHas('employee')
            ->notCurrentlyDeployed()
            ->orderBy('id')
            ->get();

        foreach ($idle as $applicant) {
            $employee = Employee::query()->where('applicant_id', $applicant->id)->first();
            $reviews[] = ['applicant' => $applicant, 'note' => sprintf(
                'Employee %s is %s, with no active deployment.',
                $employee->employee_number,
                $employee->employment_status,
            )];
        }

        return [$corrections, $reviews];
    }

    private function apply(array $correction, User $actor, AuditService $audit): bool
    {
        $applicant = $correction['applicant'];

        return DB::transaction(function () use ($applicant, $correction, $actor, $audit) {
            $current = Applicant::query()->whereKey($applicant->id)->lockForUpdate()->value('current_status');

            // Changed by somebody else since it was listed: left alone.
            if ($current !== $correction['from']) {
                $this->warn("{$applicant->applicant_code}: now {$current}; skipped.");

                return false;
            }

            $applicant->forceFill(['current_status' => $correction['to']])->save();

            ApplicationStatusHistory::create([
                'applicant_id' => $applicant->id,
                'from_status' => $correction['from'],
                'to_status' => $correction['to'],
                'reason' => $correction['reason'],
                'changed_by' => $actor->id,
                'changed_at' => now(),
            ]);

            // A deployed person is not waiting on a client, as deployment
            // itself would have recorded.
            if ($correction['deployment']) {
                Endorsement::query()
                    ->where('applicant_id', $applicant->id)
                    ->whereNull('ended_at')
                    ->get()
                    ->each(fn (Endorsement $endorsement) => $endorsement->forceFill([
                        'ended_at' => now(),
                        'end_reason' => 'deployed',
                        'deployment_id' => $correction['deployment']->id,
                        'note' => $endorsement->note ?? substr(
                            "Closed by the status correction: already deployed under {$correction['deployment']->deployment_code} when this was opened.",
                            0,
                            255,
                        ),
                    ])->save());
            }

            $audit->record(
                action: 'update',
                module: 'applicants',
                recordType: Applicant::class,
                recordId: $applicant->id,
                oldValues: ['current_status' => $correction['from']],
                newValues: [
                    'current_status' => $correction['to'],
                    'reason' => $correction['reason'],
                    'corrected_by' => 'empower:reconcile-placement-status',
                ],
                actor: $actor,
            );

            return true;
        });
    }
}
