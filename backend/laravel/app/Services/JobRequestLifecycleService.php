<?php

namespace App\Services;

use App\Exceptions\InvalidTransitionException;
use App\Models\JobRequest;
use App\Models\JobRequestStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The single doorway through which a manpower request's status changes (R-16).
 *
 * Status used to be a free field: any value could follow any other, from any
 * screen or script, and nothing recorded why a request was cancelled or
 * reopened. Every change now passes the transition map in
 * config/empower.php, writes a history row, and is audited - whether HR made
 * it or a deployment or headcount edit caused it - so no two endpoints can
 * apply different rules.
 */
class JobRequestLifecycleService
{
    /** Statuses that describe the headcount and must agree with it. */
    private const HEADCOUNT_STATUSES = ['partially_fulfilled', 'fulfilled'];

    /** Statuses a request is reopened from. */
    private const ENDED_STATUSES = ['closed', 'cancelled'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * A status change asked for by a person.
     *
     * Closing, cancelling and reopening need a reason. Reopening lands on the
     * open status that fits the headcount.
     */
    public function change(JobRequest $jobRequest, string $to, User $actor, ?string $reason): JobRequest
    {
        return DB::transaction(function () use ($jobRequest, $to, $actor, $reason) {
            $request = JobRequest::query()->whereKey($jobRequest->getKey())->lockForUpdate()->firstOrFail();
            $from = $request->request_status;

            if ($from === $to) {
                throw new RuntimeException('The request already has this status.', 409);
            }

            $allowed = $this->allowedFrom($from);

            if (! in_array($to, $allowed, true)) {
                throw new InvalidTransitionException($from, $to, $allowed);
            }

            $reopening = in_array($from, self::ENDED_STATUSES, true);

            if (($reopening || in_array($to, self::ENDED_STATUSES, true)) && blank($reason)) {
                throw ValidationException::withMessages([
                    'reason' => [match (true) {
                        $reopening => 'Give the reason for reopening this request.',
                        $to === 'cancelled' => 'Give the reason for cancelling this request.',
                        default => 'Give the reason for closing this request.',
                    }],
                ]);
            }

            if ($reopening) {
                $to = $this->openStatusFor($request);
            }

            $this->assertAgreesWithHeadcount($request, $to);

            if ($to === 'open' && $request->workers_fulfilled > 0) {
                throw new RuntimeException(
                    sprintf('%d worker(s) are already deployed against this request, so it is partially fulfilled, not open.', $request->workers_fulfilled),
                    409
                );
            }

            $this->write($request, $from, $to, $actor, $reason, 'manual');

            return $request->refresh();
        });
    }

    /**
     * Brings an open request's status into line with its headcount, after a
     * deployment or a change to the number of workers needed. Closed and
     * cancelled requests are left alone.
     */
    public function syncToHeadcount(JobRequest $jobRequest, User $actor, string $reason, string $source): JobRequest
    {
        return DB::transaction(function () use ($jobRequest, $actor, $reason, $source) {
            $request = JobRequest::query()->whereKey($jobRequest->getKey())->lockForUpdate()->firstOrFail();
            $from = $request->request_status;

            if (in_array($from, self::ENDED_STATUSES, true)) {
                return $request;
            }

            $to = match (true) {
                $request->workers_fulfilled >= $request->workers_needed => 'fulfilled',
                $request->workers_fulfilled > 0 => 'partially_fulfilled',
                in_array($from, self::HEADCOUNT_STATUSES, true) => 'open',
                default => $from,
            };

            if ($to !== $from) {
                $this->write($request, $from, $to, $actor, $reason, $source);
            }

            return $request->refresh();
        });
    }

    /**
     * The changes a person can ask for from here, as the screen offers them.
     * Headcount statuses are left out: the system sets those.
     *
     * @return list<array{status: string, requires_reason: bool}>
     */
    public function manualOptions(JobRequest $request): array
    {
        $from = $request->request_status;

        return collect($this->allowedFrom($from))
            ->reject(fn ($to) => in_array($to, self::HEADCOUNT_STATUSES, true))
            ->reject(fn ($to) => $to === 'open' && $request->workers_fulfilled > 0 && ! in_array($from, self::ENDED_STATUSES, true))
            ->map(fn ($to) => [
                'status' => $to,
                'reopens' => in_array($from, self::ENDED_STATUSES, true),
                'requires_reason' => in_array($from, self::ENDED_STATUSES, true) || in_array($to, self::ENDED_STATUSES, true),
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function allowedFrom(string $from): array
    {
        return config("empower.job_request_transitions.{$from}", []);
    }

    private function openStatusFor(JobRequest $request): string
    {
        if ($request->workers_fulfilled >= $request->workers_needed) {
            throw new RuntimeException(
                'Every position on this request is already filled. Raise the number of workers needed before reopening it.',
                409
            );
        }

        return $request->workers_fulfilled > 0 ? 'partially_fulfilled' : 'open';
    }

    private function assertAgreesWithHeadcount(JobRequest $request, string $to): void
    {
        $filled = (int) $request->workers_fulfilled;
        $needed = (int) $request->workers_needed;

        if ($to === 'fulfilled' && $filled < $needed) {
            throw new RuntimeException(sprintf(
                'Only %d of %d positions are filled, so this request cannot be marked fulfilled. Use "closed" to end it early.',
                $filled,
                $needed
            ), 409);
        }

        if ($to === 'partially_fulfilled' && ($filled === 0 || $filled >= $needed)) {
            throw new RuntimeException(sprintf(
                '%d of %d positions are filled, which is not partially fulfilled.',
                $filled,
                $needed
            ), 409);
        }
    }

    private function write(JobRequest $request, string $from, string $to, User $actor, ?string $reason, string $source): void
    {
        // forceFill: request_status is kept out of $fillable so no form can
        // set it directly.
        $request->forceFill(['request_status' => $to])->save();

        JobRequestStatusHistory::create([
            'job_request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'source' => $source,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);

        $this->audit->record(
            'update',
            'job_requests',
            JobRequest::class,
            $request->id,
            ['request_status' => $from],
            ['request_status' => $to, 'reason' => $reason, 'source' => $source],
        );
    }
}
