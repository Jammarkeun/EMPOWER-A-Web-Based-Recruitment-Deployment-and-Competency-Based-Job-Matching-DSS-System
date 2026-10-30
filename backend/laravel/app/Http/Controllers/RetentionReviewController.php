<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Services\RetentionReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The retention review list and the decisions taken on it (audit FN-12, R-27).
 *
 * Reading needs archives.view and applicants.view; recording a decision needs
 * archives.create. Archiving an unhired applicant from the list is the
 * ordinary status change, with its own permission checks. Nothing here
 * deletes anything.
 */
class RetentionReviewController extends Controller
{
    public function __construct(private readonly RetentionReviewService $retention)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewRetentionReview');

        $filters = $request->validate([
            'category' => ['nullable', Rule::in(RetentionReviewService::CATEGORIES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $all = $this->retention->due();
        $rows = $all['rows'];
        $counts = $rows->countBy('category');

        if (! empty($filters['category'])) {
            $rows = $rows->where('category', $filters['category'])->values();
        }

        $perPage = $filters['per_page'] ?? 50;
        $page = $filters['page'] ?? 1;
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page);

        return ApiResponse::success([
            'policy' => $this->retention->policy(),
            'counts' => [
                RetentionReviewService::UNHIRED => $counts[RetentionReviewService::UNHIRED] ?? 0,
                RetentionReviewService::CLOSED_DOCUMENTS => $counts[RetentionReviewService::CLOSED_DOCUMENTS] ?? 0,
            ],
            // Closed records whose closing date is not on file: not guessed
            // at, so not listed, but not hidden either.
            'undated_closed_records' => $all['undated'],
            'rows' => $paginator->items(),
        ], 'Retention review list retrieved', 200, [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('recordRetentionDecision');

        $data = $request->validate([
            'category' => ['required', Rule::in(RetentionReviewService::CATEGORIES)],
            'applicant_id' => ['required', 'integer', 'exists:applicants,id'],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
            'retain_until' => ['required', 'date', 'after:today', 'before_or_equal:'.today()->addYears(10)->toDateString()],
        ], [
            'note.required' => 'Say why the record is being kept.',
            'retain_until.after' => 'Choose a date after today.',
        ]);

        $review = $this->retention->retain(
            $data['category'],
            Applicant::findOrFail($data['applicant_id']),
            trim($data['note']),
            Carbon::parse($data['retain_until']),
            $request->user(),
        );

        return ApiResponse::created([
            'id' => $review->id,
            'category' => $review->category,
            'applicant_id' => $review->applicant_id,
            'retain_until' => $review->retain_until->toDateString(),
        ], 'Kept on file until '.$review->retain_until->format('j M Y').'.');
    }
}
