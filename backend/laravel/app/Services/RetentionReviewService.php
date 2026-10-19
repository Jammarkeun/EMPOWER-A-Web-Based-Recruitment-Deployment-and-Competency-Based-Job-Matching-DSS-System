<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\ApplicationStatusHistory;
use App\Models\Archive;
use App\Models\RetentionReview;
use App\Models\User;
use App\Support\ApplicantStatuses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The retention review list (audit FN-12, R-27).
 *
 * CDE's own estimates, confirmed in the follow-up interview and held as
 * Settings: unhired applicants are kept about three months "depending on the
 * situation", and legal documents five to ten years. Both are prompts for a
 * person, not deadlines (docs/02-analysis/deployment-workflow.md §5.6), so
 * this service only ever lists. It never archives and never deletes. A
 * reviewer either keeps a record on file until a date, or - for an unhired
 * applicant - archives it through the ordinary lifecycle move.
 *
 * Where each clock starts is our reading, not a stated CDE rule, and is said
 * on every row:
 *
 * - Unhired applicant: from the last recorded activity on the application -
 *   the application date, a status change, or a document being added or
 *   reviewed - so someone still moving through the process is never listed.
 * - Legal documents: from the day the record was closed (resigned, terminated
 *   or archived), since a current employee's papers are still in use.
 *
 * A closed record whose closing date is not on file is not guessed at: it is
 * counted, so the reviewer knows it exists, and left off the list.
 */
class RetentionReviewService
{
    public const UNHIRED = 'unhired_applicant';

    public const CLOSED_DOCUMENTS = 'closed_record_documents';

    public const CATEGORIES = [self::UNHIRED, self::CLOSED_DOCUMENTS];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function policy(): array
    {
        return [
            'unhired_applicant_months' => (int) config('empower.retention.unhired_applicant_months', 3),
            'legal_document_years' => (int) config('empower.retention.legal_document_years', 5),
        ];
    }

    /**
     * Everything due for review, earliest due first.
     *
     * @return array{rows: Collection, undated: int}
     */
    public function due(?string $category = null): array
    {
        $rows = collect();
        $undated = 0;

        if ($category === null || $category === self::UNHIRED) {
            $rows = $rows->concat($this->dueUnhiredApplicants());
        }

        if ($category === null || $category === self::CLOSED_DOCUMENTS) {
            [$documents, $undated] = $this->dueClosedRecordDocuments();
            $rows = $rows->concat($documents);
        }

        return [
            'rows' => $rows->sortBy([['due_on', 'asc'], ['applicant.full_name', 'asc']])->values(),
            'undated' => $undated,
        ];
    }

    /**
     * Keeps a listed record on file until a date, with the reason.
     *
     * Only for a record that is due now in that category: the list is the
     * only place this decision is taken, and a decision about a record that
     * is not due would only hide it from a later review.
     */
    public function retain(string $category, Applicant $applicant, string $note, Carbon $until, User $reviewer): RetentionReview
    {
        $row = $this->due($category)['rows']->first(fn (array $row) => $row['applicant']['id'] === $applicant->id);

        if (! $row) {
            throw new RuntimeException('This record is not due for review in that category, so nothing was recorded.', 409);
        }

        if (! $until->isFuture()) {
            throw new RuntimeException('Choose a date after today to keep the record on file until.', 422);
        }

        return DB::transaction(function () use ($category, $applicant, $note, $until, $reviewer) {
            $policy = $this->policy();

            $review = RetentionReview::create([
                'category' => $category,
                'applicant_id' => $applicant->id,
                'decision' => 'retain',
                'note' => $note,
                'retain_until' => $until->toDateString(),
                'policy_value' => $category === self::UNHIRED ? $policy['unhired_applicant_months'] : $policy['legal_document_years'],
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $this->audit->record('create', 'archives', RetentionReview::class, $review->id, null, [
                'category' => $category,
                'applicant_code' => $applicant->applicant_code,
                'decision' => 'retain',
                'retain_until' => $review->retain_until->toDateString(),
                'note' => $note,
            ]);

            return $review;
        });
    }

    // ------------------------------------------------------------ categories

    private function dueUnhiredApplicants(): Collection
    {
        $months = $this->policy()['unhired_applicant_months'];
        $cutoff = today()->subMonths($months);

        // Anybody still in recruitment: not employed, and not closed.
        $recruitment = array_values(array_diff(
            ApplicantStatuses::all(),
            ApplicantStatuses::CLOSED,
            ApplicantStatuses::STAGES['deployed']['statuses'],
        ));

        $candidates = Applicant::query()
            ->whereIn('current_status', $recruitment)
            // Nobody who applied after the cut-off can have been idle that long.
            ->where(fn ($q) => $q->whereDate('application_date', '<=', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('application_date')->whereDate('created_at', '<=', $cutoff)))
            ->withMax('statusHistory as last_status_change_at', 'changed_at')
            ->withMax('requirements as last_document_activity_at', 'updated_at')
            ->get();

        $decisions = $this->latestDecisions(self::UNHIRED, $candidates->pluck('id'));

        return $candidates->map(function (Applicant $applicant) use ($months, $decisions) {
            $lastActivity = collect([
                $applicant->application_date ?? $applicant->created_at,
                $applicant->last_status_change_at,
                $applicant->last_document_activity_at,
            ])->filter()->map(fn ($date) => Carbon::parse($date))->max();

            $dueOn = $lastActivity->copy()->startOfDay()->addMonths($months);

            return $this->row(
                category: self::UNHIRED,
                applicant: $applicant,
                relevantDate: $lastActivity,
                relevantDateLabel: 'Last recorded activity',
                dueOn: $dueOn,
                reason: sprintf(
                    'No recorded activity on this application since %s. CDE holds unhired applicants about %d month%s, depending on the situation.',
                    $lastActivity->format('j M Y'),
                    $months,
                    $months === 1 ? '' : 's'
                ),
                decision: $decisions[$applicant->id] ?? null,
                actions: ['retain', 'archive'],
            );
        })->filter()->values();
    }

    /** @return array{0: Collection, 1: int} */
    private function dueClosedRecordDocuments(): array
    {
        $years = $this->policy()['legal_document_years'];

        $candidates = Applicant::query()
            ->whereIn('current_status', ApplicantStatuses::CLOSED)
            ->whereHas('requirements', fn ($q) => $q->whereNotNull('file_path'))
            ->withCount(['requirements as documents_on_file' => fn ($q) => $q->whereNotNull('file_path')])
            ->get();

        $closedOn = $this->closingDates($candidates);
        $decisions = $this->latestDecisions(self::CLOSED_DOCUMENTS, $candidates->pluck('id'));
        $undated = 0;

        $rows = $candidates->map(function (Applicant $applicant) use ($years, $closedOn, $decisions, &$undated) {
            $closed = $closedOn[$applicant->id] ?? null;

            if ($closed === null) {
                $undated++;

                return null;
            }

            return $this->row(
                category: self::CLOSED_DOCUMENTS,
                applicant: $applicant,
                relevantDate: $closed,
                relevantDateLabel: 'Record closed',
                dueOn: $closed->copy()->startOfDay()->addYears($years),
                reason: sprintf(
                    'Record closed on %s (%s) with %d document%s on file. CDE keeps legal documents %d to 10 years; the review falls due at %d.',
                    $closed->format('j M Y'),
                    strtolower(ApplicantStatuses::label($applicant->current_status)),
                    $applicant->documents_on_file,
                    $applicant->documents_on_file === 1 ? '' : 's',
                    $years,
                    $years
                ),
                decision: $decisions[$applicant->id] ?? null,
                // Disposal is not offered: no approved procedure exists for
                // destroying legal documents, and the policy says to prompt.
                actions: ['retain'],
                extra: ['documents_on_file' => $applicant->documents_on_file],
            );
        })->filter()->values();

        return [$rows, $undated];
    }

    // --------------------------------------------------------------- helpers

    /**
     * The day each closed record reached its current status, from the
     * history the lifecycle writes - or, failing that, its archive entry.
     *
     * @return array<int, Carbon>
     */
    private function closingDates(Collection $applicants): array
    {
        if ($applicants->isEmpty()) {
            return [];
        }

        $dates = [];

        ApplicationStatusHistory::query()
            ->whereIn('applicant_id', $applicants->pluck('id'))
            ->whereIn('to_status', ApplicantStatuses::CLOSED)
            ->groupBy('applicant_id', 'to_status')
            ->selectRaw('applicant_id, to_status, MAX(changed_at) as closed_at')
            ->get()
            ->each(function ($row) use ($applicants, &$dates) {
                $applicant = $applicants->firstWhere('id', $row->applicant_id);
                if ($applicant && $applicant->current_status === $row->to_status && $row->closed_at) {
                    $dates[$row->applicant_id] = Carbon::parse($row->closed_at);
                }
            });

        Archive::query()
            ->where('entity_type', 'applicant')
            ->whereIn('entity_id', $applicants->pluck('id')->diff(array_keys($dates)))
            ->get(['entity_id', 'archived_at'])
            ->each(function (Archive $archive) use (&$dates) {
                if ($archive->archived_at) {
                    $dates[$archive->entity_id] ??= Carbon::parse($archive->archived_at);
                }
            });

        return $dates;
    }

    /** @return array<int, RetentionReview> */
    private function latestDecisions(string $category, Collection $applicantIds): array
    {
        if ($applicantIds->isEmpty()) {
            return [];
        }

        return RetentionReview::query()
            ->with('reviewer')
            ->where('category', $category)
            ->whereIn('applicant_id', $applicantIds)
            ->orderBy('reviewed_at')
            ->orderBy('id')
            ->get()
            ->keyBy('applicant_id') // the last one wins
            ->all();
    }

    /**
     * One row of the list, or null when the record is not due today - either
     * its date has not come, or a reviewer kept it on file until a later day.
     */
    private function row(
        string $category,
        Applicant $applicant,
        Carbon $relevantDate,
        string $relevantDateLabel,
        Carbon $dueOn,
        string $reason,
        ?RetentionReview $decision,
        array $actions,
        array $extra = [],
    ): ?array {
        if ($dueOn->isFuture()) {
            return null;
        }

        if ($decision && $decision->retain_until->isFuture()) {
            return null;
        }

        return array_merge([
            'key' => $category.':'.$applicant->id,
            'category' => $category,
            'category_label' => $category === self::UNHIRED ? 'Unhired applicant' : 'Legal documents of a closed record',
            'applicant' => [
                'id' => $applicant->id,
                'applicant_code' => $applicant->applicant_code,
                'full_name' => $applicant->full_name,
                'current_status' => $applicant->current_status,
                'status_label' => ApplicantStatuses::label($applicant->current_status),
            ],
            'relevant_date' => $relevantDate->toDateString(),
            'relevant_date_label' => $relevantDateLabel,
            'due_on' => $dueOn->toDateString(),
            'days_overdue' => (int) $dueOn->diffInDays(today()),
            'reason' => $reason,
            // A decision whose "keep until" date has passed: shown so the
            // reviewer sees it was looked at before, and why it was kept.
            'previous_decision' => $decision ? [
                'retain_until' => $decision->retain_until->toDateString(),
                'note' => $decision->note,
                'reviewed_by' => $decision->reviewer?->full_name,
                'reviewed_at' => $decision->reviewed_at?->toIso8601String(),
            ] : null,
            'actions' => $actions,
        ], $extra);
    }
}
