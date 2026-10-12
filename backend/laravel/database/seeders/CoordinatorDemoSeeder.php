<?php

namespace Database\Seeders;

use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\CoordinatorAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\ReferenceCodeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Two coordinators on two clients, so the isolation between them can be
 * seen rather than taken on trust.
 *
 * Coordinator A covers Best Tiwi; Coordinator B covers a second, fictitious
 * client. Signing in as A must show nothing of B's client, and the reverse.
 * One report from A is left waiting for HR, so the review screen has
 * something on it. Passwords come from SEED_COORDINATOR_PASSWORD and must be
 * changed before anyone real uses these accounts.
 *
 * DemoDataSeeder runs this at the end. It also runs on its own, so a database
 * already in use gains the coordinators without its demo applicants being
 * reset:
 *
 *     php artisan db:seed --class=CoordinatorDemoSeeder
 *
 * Running it again changes nothing that has since been changed by hand: a
 * password that was reset stays reset, and a coordinator who was reassigned
 * or unassigned is not put back.
 */
class CoordinatorDemoSeeder extends Seeder
{
    public function run(): void
    {
        $hr = User::where('email', 'hr@cdemanpower.local')->firstOrFail();
        $admin = User::where('email', 'admin@cdemanpower.local')->first() ?? $hr;
        $codes = app(ReferenceCodeService::class);
        $bestTiwi = ClientCompany::where('company_name', 'Best Tiwi Food Products Corporation')->firstOrFail();

        $clientB = ClientCompany::updateOrCreate(
            ['company_name' => 'Laguna Pack & Print Inc. (demo)'],
            [
                'company_code' => ClientCompany::where('company_name', 'Laguna Pack & Print Inc. (demo)')->value('company_code')
                    ?? $codes->client(),
                'business_type' => 'Packaging',
                'contact_person' => 'Demo Contact',
                'office_address' => 'Calamba, Laguna',
                'status' => 'active',
                'created_by' => $hr->id,
            ]
        );

        ClientDepartment::updateOrCreate(
            ['client_company_id' => $clientB->id, 'department_code' => 'ASSY'],
            ['department_name' => 'Assembly', 'status' => 'active', 'created_by' => $hr->id]
        );

        $coordinators = [
            ['coordinator.a@cdemanpower.local', 'Coordinator', 'User A', $bestTiwi],
            ['coordinator.b@cdemanpower.local', 'Coordinator', 'User B', $clientB],
        ];

        foreach ($coordinators as [$email, $first, $last, $company]) {
            $user = User::firstOrNew(['email' => $email]);
            $isNew = ! $user->exists;

            $user->fill(['first_name' => $first, 'last_name' => $last]);

            if ($isNew) {
                $user->fill([
                    'password' => Hash::make(env('SEED_COORDINATOR_PASSWORD', 'ChangeMe123!')),
                    'user_type' => 'coordinator',
                    'is_active' => true,
                ]);
            }

            $user->save();
            $user->syncRoles(['coordinator']);

            if ($isNew) {
                CoordinatorAssignment::create([
                    'coordinator_user_id' => $user->id,
                    'client_company_id' => $company->id,
                    'status' => 'active',
                    'assigned_at' => now(),
                    'assigned_by' => $admin->id,
                    'remarks' => 'Demo assignment.',
                ]);
            }
        }

        $coordinatorA = User::where('email', 'coordinator.a@cdemanpower.local')->first();
        $employee = Employee::where('current_client_company_id', $bestTiwi->id)->active()->orderBy('id')->first();

        if ($employee && ! ViolationReport::where('reported_by', $coordinatorA->id)->exists()) {
            ViolationReport::create([
                'report_code' => $codes->violationReport(),
                'employee_id' => $employee->id,
                'client_company_id' => $bestTiwi->id,
                'client_department_id' => $employee->current_department_id,
                'reported_by' => $coordinatorA->id,
                'violation_type' => 'awol',
                'incident_date' => now()->subDays(2)->toDateString(),
                'details' => 'Did not report for the morning shift and could not be reached. Reported by the department supervisor.',
                'status' => 'submitted',
                'submitted_at' => now()->subDay(),
            ]);
        }

        $this->command?->warn('Seeded coordinator.a@ and coordinator.b@cdemanpower.local. Change their passwords before real use.');
    }
}
