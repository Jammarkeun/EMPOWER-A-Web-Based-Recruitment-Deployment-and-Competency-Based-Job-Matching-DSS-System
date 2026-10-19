<?php

namespace App\Policies;

use App\Models\JobRequest;
use App\Models\User;

class JobRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('job_requests.view');
    }

    public function view(User $user, JobRequest $request): bool
    {
        return $user->can('job_requests.view');
    }

    public function create(User $user): bool
    {
        return $user->can('job_requests.create');
    }

    public function update(User $user, JobRequest $request): bool
    {
        return $user->can('job_requests.update');
    }

    public function close(User $user, JobRequest $request): bool
    {
        return $user->can('job_requests.close');
    }

    public function configureCriteria(User $user, JobRequest $request): bool
    {
        return $user->can('job_requests.configure_criteria');
    }

    public function evaluate(User $user, JobRequest $request): bool
    {
        return $user->can('matching.evaluate');
    }

    /**
     * The ranked candidates for a request - matching.view, on top of being
     * allowed to see the request itself.
     */
    public function viewRankings(User $user, JobRequest $request): bool
    {
        return $user->can('job_requests.view') && $user->can('matching.view');
    }

    public function shortlist(User $user, JobRequest $request): bool
    {
        return $user->can('matching.shortlist');
    }
}
