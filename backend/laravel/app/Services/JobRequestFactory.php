<?php

namespace App\Services;

use App\Models\ClientCompany;
use App\Models\JobPosition;
use App\Models\JobRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a new manpower request, whichever way it arrived: recorded directly
 * by HR, or created from a coordinator requisition when HR approves it. Both
 * go through the same code numbering and the same position rules.
 */
class JobRequestFactory
{
    public function __construct(private readonly ReferenceCodeService $codes)
    {
    }

    /**
     * @param  array<string, mixed>  $data  JobRequest attributes; either
     *                                      `job_position_id` or `position_title`
     */
    public function create(ClientCompany $company, array $data, User $actor): JobRequest
    {
        return DB::transaction(function () use ($company, $data, $actor) {
            $position = $this->resolvePosition($company, $data, $actor);

            return JobRequest::create(array_merge($data, [
                'request_code' => $this->codes->jobRequest(),
                'client_company_id' => $company->id,
                'job_position_id' => $position->id,
                // Kept in step with the position, so the two never disagree
                // about what the job is called.
                'position_title' => $position->position_title,
                'created_by' => $actor->id,
            ]));
        });
    }

    /**
     * The client's existing position with this title, if there is one.
     * Case-insensitive, so "Production Helper" and "production helper" are one
     * job rather than two.
     */
    public function findPosition(ClientCompany|int $company, string $title): ?JobPosition
    {
        return JobPosition::where('client_company_id', $company instanceof ClientCompany ? $company->id : $company)
            ->whereRaw('LOWER(position_title) = ?', [mb_strtolower(trim($title))])
            ->first();
    }

    /**
     * The position this request is for, creating it if the title is a new one.
     *
     * The find-or-create is the mechanism that keeps the applicant's list of
     * positions honest without anyone having to maintain it as a separate
     * chore. A client asks for a role the agency has not placed before, HR
     * records or approves the request, and the role is on the application
     * form from that moment.
     */
    private function resolvePosition(ClientCompany $company, array $data, User $actor): JobPosition
    {
        if (! empty($data['job_position_id'])) {
            $position = JobPosition::findOrFail($data['job_position_id']);

            if (! is_null($position->client_company_id) && (int) $position->client_company_id !== (int) $company->id) {
                throw new RuntimeException('That position belongs to a different client company.', 422);
            }

            return $position;
        }

        $title = trim($data['position_title']);

        if ($existing = $this->findPosition($company, $title)) {
            // A role being asked for again is a role the agency still places,
            // so a previously withdrawn position comes back into use rather
            // than leaving the request pointing at something switched off.
            if (! $existing->isActive()) {
                $existing->update(['status' => 'active']);
            }

            return $existing;
        }

        return JobPosition::create([
            'position_code' => $this->codes->position(),
            'client_company_id' => $company->id,
            'position_title' => $title,
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
    }
}
