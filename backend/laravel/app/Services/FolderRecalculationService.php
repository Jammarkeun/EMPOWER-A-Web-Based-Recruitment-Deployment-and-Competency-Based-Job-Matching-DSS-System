<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\FolderRecalculation;
use App\Models\RequirementType;
use App\Models\User;
use App\Support\DocumentCompliance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings every stored folder back in line with the current rules (audit R-17).
 *
 * The folder is stored so lists and dashboard counts can filter on it, and it
 * used to be recomputed only when one of the applicant's own documents
 * changed. Making a document required, moving it between primary and final,
 * or retiring it in Settings changed the rule for everybody at once while the
 * stored folders stayed as they were; so did a clearance lapsing overnight.
 *
 * What this does and does not touch:
 *
 *  - Only folder_category is written. Lifecycle statuses are left exactly as
 *    they are: an applicant who is Ready for Deployment and now lacks a newly
 *    required document stays Ready for Deployment, and the deployment gate
 *    (which requires Folder 1) holds them until the document is verified.
 *    Nobody is moved backwards or forwards through the pipeline here.
 *  - Every applicant is checked, not only those the change could affect, so
 *    each run also repairs any drift from before it. It is cheap: two queries
 *    per batch, plus one update per folder that actually changes.
 *  - Each batch is its own transaction with the batch's rows locked, so a
 *    document being verified at the same moment either lands before the batch
 *    reads it or recalculates after it (FolderCategoryService::recalculate
 *    takes the same lock). Batches lock in ascending id order, so two runs
 *    cannot deadlock each other.
 *  - A run that fails is recorded as failed with how far it got. Batches
 *    already finished stay finished, and running again is always safe.
 */
class FolderRecalculationService
{
    private const BATCH = 250;

    /** The dashboard summary key the screen requests; see DashboardController. */
    private const DASHBOARD_CACHE_KEY = 'empower.dashboard.summary.6';

    public function __construct(
        private readonly FolderCategoryService $folders,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Records a run and performs it. Never throws: whatever triggered the run
     * has already been saved, and is not to be reported as failed because the
     * follow-up did not finish. The run record says what happened instead.
     */
    public function run(
        string $trigger,
        ?User $actor = null,
        ?string $reason = null,
        ?RequirementType $type = null,
    ): FolderRecalculation {
        $run = FolderRecalculation::create([
            'trigger' => $trigger,
            'requirement_type_id' => $type?->id,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'status' => 'running',
            'requested_by' => $actor?->id,
            'started_at' => now(),
        ]);

        $checked = 0;
        $moves = [];
        $changed = [];

        try {
            $lastId = 0;

            while (true) {
                $ids = Applicant::query()
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit(self::BATCH)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    break;
                }

                foreach ($this->recalculateBatch($ids) as [$id, $from, $to]) {
                    $moves["{$from}>{$to}"] = ($moves["{$from}>{$to}"] ?? 0) + 1;
                    $changed[] = ['applicant_id' => $id, 'from' => $from, 'to' => $to];
                }

                $checked += $ids->count();
                $lastId = $ids->last();

                // Progress is kept as it goes, so a run that dies part-way
                // still shows how far it reached.
                $run->forceFill([
                    'applicants_checked' => $checked,
                    'applicants_changed' => count($changed),
                ])->save();
            }

            $run->forceFill([
                'status' => 'completed',
                'summary' => $moves,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            // The run record and anything shown on screen carry no SQL or
            // stack trace. The log gets the cause - for a database error only
            // its code, as AuditService does, since the message would carry
            // bound values.
            Log::error('Folder recalculation failed', [
                'run' => $run->id,
                'checked' => $checked,
                'exception' => $e instanceof QueryException
                    ? get_class($e).' SQLSTATE '.($e->errorInfo[0] ?? $e->getCode())
                    : get_class($e).': '.$e->getMessage(),
            ]);

            $run->forceFill([
                'status' => 'failed',
                'summary' => $moves,
                'applicants_checked' => $checked,
                'applicants_changed' => count($changed),
                'error' => "Stopped after checking {$checked} applicant(s). Applicants already checked keep their "
                    .'corrected folders; run the recalculation again to finish.',
                'finished_at' => now(),
            ])->save();
        }

        if ($changed !== []) {
            Cache::forget(self::DASHBOARD_CACHE_KEY);
        }

        $this->audit->record(
            action: 'update',
            module: 'requirements',
            recordType: FolderRecalculation::class,
            recordId: $run->id,
            newValues: [
                'folder_recalculation' => $trigger,
                'status' => $run->status,
                'applicants_checked' => $checked,
                'applicants_changed' => count($changed),
                // Which records moved, so a change can be traced afterwards.
                // Identifiers and folder names only.
                'changes' => array_slice($changed, 0, 500),
            ],
            actor: $actor,
        );

        return $run->fresh(['requester']);
    }

    /** The most recent run, for the Settings screen. */
    public function latest(): ?FolderRecalculation
    {
        return FolderRecalculation::with('requester')->latest('id')->first();
    }

    /**
     * Recomputes one batch under lock. Returns [id, from, to] for each folder
     * that changed.
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, array{0: int, 1: string, 2: string}>
     */
    private function recalculateBatch(Collection $ids): array
    {
        return DB::transaction(function () use ($ids) {
            // Locked first, so everything read after this sees whatever was
            // committed before the lock was granted.
            $applicants = Applicant::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'folder_category', 'current_status']);

            // Read inside the batch, not once per run: a second Settings change
            // made while a long run is going is picked up from the next batch.
            $required = $this->folders->requiredTypes();
            $rules = $required->pluck('post_deployment_rule', 'id');
            // Judged by the same rule as a single recalculation, including
            // whether the person is deployed yet (DocumentCompliance).
            $stages = $applicants->mapWithKeys(fn (Applicant $a) => [$a->id => DocumentCompliance::stage($a->current_status)]);

            $verified = ApplicantRequirement::query()
                ->whereIn('applicant_id', $ids)
                ->get(['id', 'applicant_id', 'requirement_type_id', 'status', 'expiry_date', 'verified_at'])
                ->filter(fn (ApplicantRequirement $r) => $r->countsAsComplete(
                    $rules->get($r->requirement_type_id),
                    $stages->get($r->applicant_id, DocumentCompliance::STAGE_PRE_DEPLOYMENT),
                ))
                ->groupBy('applicant_id')
                ->map(fn (Collection $rows) => $rows->pluck('requirement_type_id'));

            $changed = [];

            foreach ($applicants as $applicant) {
                $category = $this->folders->categoryFor(
                    $required,
                    $verified->get($applicant->id, collect()),
                );

                if ($applicant->folder_category !== $category) {
                    Applicant::query()
                        ->whereKey($applicant->id)
                        ->update(['folder_category' => $category]);

                    $changed[] = [$applicant->id, (string) $applicant->folder_category, $category];
                }
            }

            return $changed;
        });
    }
}
