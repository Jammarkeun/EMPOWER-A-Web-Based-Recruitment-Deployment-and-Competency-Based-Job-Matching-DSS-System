<?php

namespace App\Services;

use App\Models\ClientCompany;
use App\Models\CoordinatorAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Puts a coordinator on a client, or moves them to another.
 *
 * The assignment is what a coordinator's access is computed from, so it changes
 * only here, only by an administrator, and never by overwriting: the current
 * assignment is ended and a new one begins. Reports and audit entries made
 * under the old assignment keep pointing at the client they were made for.
 */
class CoordinatorAssignmentService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function assign(User $coordinator, ClientCompany $company, User $actor, ?string $remarks = null): CoordinatorAssignment
    {
        if (! $coordinator->isCoordinator()) {
            throw new RuntimeException('Only a coordinator account can be assigned to a client company.', 422);
        }

        if (! $company->isActive()) {
            throw new RuntimeException('This client company is inactive. Assign the coordinator to an active client.', 422);
        }

        return DB::transaction(function () use ($coordinator, $company, $actor, $remarks) {
            // Locked so two administrators reassigning the same coordinator at
            // once cannot both leave an active assignment behind.
            $current = CoordinatorAssignment::query()
                ->where('coordinator_user_id', $coordinator->id)
                ->active()
                ->lockForUpdate()
                ->get();

            if ($current->count() === 1 && (int) $current->first()->client_company_id === (int) $company->id) {
                throw new RuntimeException('This coordinator is already assigned to that client company.', 422);
            }

            foreach ($current as $assignment) {
                $assignment->update([
                    'status' => 'ended',
                    'unassigned_at' => now(),
                    'ended_by' => $actor->id,
                ]);
            }

            $assignment = CoordinatorAssignment::create([
                'coordinator_user_id' => $coordinator->id,
                'client_company_id' => $company->id,
                'status' => 'active',
                'assigned_at' => now(),
                'assigned_by' => $actor->id,
                'remarks' => $remarks,
            ]);

            $this->audit->record(
                $current->isEmpty() ? 'assign' : 'reassign',
                'users',
                User::class,
                $coordinator->id,
                $current->isEmpty() ? null : ['client_company_id' => $current->first()->client_company_id],
                [
                    'coordinator' => $coordinator->full_name,
                    'client_company_id' => $company->id,
                    'client_company' => $company->company_name,
                    'remarks' => $remarks,
                ],
                clientCompanyId: $company->id,
            );

            return $assignment;
        });
    }
}
