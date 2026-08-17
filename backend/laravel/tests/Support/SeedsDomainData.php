<?php

namespace Tests\Support;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\JobRequest;
use App\Models\RequirementType;
use App\Models\User;
use Database\Seeders\CriteriaCatalogSeeder;
use Database\Seeders\RequirementTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Builders for the records nearly every test needs, so individual test methods
 * stay focused on the behaviour under examination.
 */
trait SeedsDomainData
{
    protected function seedReferenceData(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RequirementTypesSeeder::class);
        $this->seed(CriteriaCatalogSeeder::class);
    }

    /*
     * Emails are made unique per call. Several tests legitimately need more than
     * one staff account - checking that the last administrator cannot be
     * removed, for instance - and a fixed address collides on the unique index.
     */
    protected function hrUser(): User
    {
        $user = User::factory()->create([
            'email' => 'hr.'.uniqid().'@cdemanpower.local',
            'user_type' => 'hr',
        ]);
        $user->assignRole('hr');

        return $user;
    }

    protected function adminUser(): User
    {
        $user = User::factory()->create([
            'email' => 'admin.'.uniqid().'@cdemanpower.local',
            'user_type' => 'admin',
        ]);
        $user->assignRole('admin');

        return $user;
    }

    protected function clientCompany(User $actor, array $overrides = []): ClientCompany
    {
        return ClientCompany::create(array_merge([
            'company_code' => 'CLI-TEST-'.uniqid(),
            'company_name' => 'Best Tiwi Food Products Corporation',
            'business_type' => 'Food Manufacturing',
            'office_address' => 'Sta. Cruz, Laguna',
            'status' => 'active',
            'created_by' => $actor->id,
        ], $overrides));
    }

    protected function department(ClientCompany $company, User $actor, array $overrides = []): ClientDepartment
    {
        return ClientDepartment::create(array_merge([
            'client_company_id' => $company->id,
            'department_code' => 'PROD',
            'department_name' => 'Production',
            'status' => 'active',
            'created_by' => $actor->id,
        ], $overrides));
    }

    protected function jobRequest(ClientDepartment $department, User $actor, array $overrides = []): JobRequest
    {
        // request_status is guarded on the model, so it is applied separately
        // rather than through create().
        $status = $overrides['request_status'] ?? 'open';
        unset($overrides['request_status']);

        $request = JobRequest::create(array_merge([
            'request_code' => 'JR-TEST-'.uniqid(),
            'client_company_id' => $department->client_company_id,
            'client_department_id' => $department->id,
            'position_title' => 'Production Helper',
            'workers_needed' => 5,
            'date_requested' => now()->toDateString(),
            'created_by' => $actor->id,
        ], $overrides));

        $request->forceFill(['request_status' => $status])->save();

        return $request;
    }

    protected function applicant(User $actor, array $overrides = []): Applicant
    {
        $status = $overrides['current_status'] ?? 'applied';
        unset($overrides['current_status']);

        $applicant = Applicant::create(array_merge([
            'applicant_code' => 'APP-TEST-'.uniqid(),
            'source_channel' => 'walk_in',
            'first_name' => 'Test',
            'last_name' => 'Applicant',
            'sex' => 'female',
            'birth_date' => now()->subYears(25)->toDateString(),
            'present_address' => 'Sta. Cruz, Laguna',
            'application_date' => now()->toDateString(),
            'created_by' => $actor->id,
        ], $overrides));

        $applicant->forceFill(['current_status' => $status])->save();

        return $applicant;
    }

    /**
     * Marks an applicant's documents as verified.
     *
     * @param  string  $scope  'primary', 'final', 'all', or 'none'
     */
    protected function verifyRequirements(Applicant $applicant, User $actor, string $scope): void
    {
        $types = RequirementType::active()->where('is_required', true)->get();

        foreach ($types as $type) {
            $verified = match ($scope) {
                'all' => true,
                'primary' => $type->requirement_group === 'primary',
                'final' => $type->requirement_group === 'final',
                default => false,
            };

            ApplicantRequirement::updateOrCreate(
                ['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id],
                [
                    'status' => $verified ? 'verified' : 'missing',
                    'verified_at' => $verified ? now() : null,
                    'verified_by' => $verified ? $actor->id : null,
                ]
            );
        }

        $applicant->unsetRelation('requirements');
    }
}
