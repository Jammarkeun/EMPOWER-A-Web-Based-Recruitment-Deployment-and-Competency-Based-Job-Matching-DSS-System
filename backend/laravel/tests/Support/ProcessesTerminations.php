<?php

namespace Tests\Support;

use App\Models\Termination;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Takes a termination request through the stages before finalisation: sent
 * for review, approved by an administrator who did not file it, and every
 * clearance item resolved by HR.
 *
 * For tests about what happens at or after finalisation, which would
 * otherwise repeat the whole review in each one. The tests of the review and
 * the clearance themselves are in TerminationReviewWorkflowTest.
 *
 * Leaves the given finaliser (HR by default) signed in.
 */
trait ProcessesTerminations
{
    protected function approveAndClearTermination(int $employeeId, int $terminationId, ?User $finaliser = null): void
    {
        $termination = Termination::findOrFail($terminationId);
        $finaliser ??= $this->hrUser();

        if (in_array($termination->status, ['draft', 'returned'], true)) {
            $this->switchUser($finaliser);
            $this->postJson("/api/v1/employees/{$employeeId}/terminations/{$terminationId}/submit")->assertOk();
        }

        $this->switchUser($this->adminUser());
        $this->postJson("/api/v1/employees/{$employeeId}/terminations/{$terminationId}/review", ['decision' => 'approve'])
            ->assertOk();

        $this->switchUser($finaliser);

        foreach ($termination->fresh()->clearanceItems as $item) {
            $this->putJson("/api/v1/employees/{$employeeId}/terminations/{$terminationId}/clearance-items/{$item->id}", [
                'status' => 'cleared',
                'remarks' => 'Verified with the office concerned.',
            ])->assertOk();
        }
    }

    private function switchUser(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }
}
