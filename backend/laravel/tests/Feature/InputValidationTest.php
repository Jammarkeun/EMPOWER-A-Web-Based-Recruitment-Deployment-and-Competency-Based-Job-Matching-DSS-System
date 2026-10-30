<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ClientCompany;
use App\Models\CriteriaCatalog;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Every field accepts only what it is for, whatever sends it.
 *
 * Sent straight to the API, the way a request that skips the forms would be,
 * so these hold even where the browser's checks are bypassed.
 */
class InputValidationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->hr = $this->hrUser();
        Sanctum::actingAs($this->hr);
    }

    private function applicantPayload(array $overrides = []): array
    {
        return array_merge([
            'source_channel' => 'walk_in',
            'first_name' => 'Maria Cristina',
            'last_name' => 'Santos',
            'birth_date' => now()->subYears(24)->toDateString(),
            'present_address' => 'Brgy. Bubukal, Sta. Cruz, Laguna',
            'application_date' => now()->toDateString(),
        ], $overrides);
    }

    private function requestPayload(array $overrides = []): array
    {
        $department = $this->department($this->clientCompany($this->hr), $this->hr);

        return array_merge([
            'client_company_id' => $department->client_company_id,
            'client_department_id' => $department->id,
            'position_title' => 'Production Helper',
            'workers_needed' => 2,
            'date_requested' => now()->toDateString(),
        ], $overrides);
    }

    // --------------------------------------------------------- phone numbers

    public function test_a_person_s_mobile_number_is_stored_as_eleven_digits_starting_09(): void
    {
        // The usual ways of writing the same number are all tidied to one form,
        // so records saved before the rule existed can still be saved.
        foreach (['09171234567', '0917 123 4567', '0917-123-4567', '+63 917 123 4567', '639171234567'] as $i => $phone) {
            $id = $this->postJson('/api/v1/applicants', $this->applicantPayload([
                'first_name' => ['Ana', 'Ben', 'Carla', 'Dina', 'Ella'][$i],
                'contact_number' => $phone,
            ]))->assertCreated()->json('data.id');

            $this->assertSame('09171234567', Applicant::find($id)->contact_number);
        }
    }

    public function test_anything_but_an_eleven_digit_09_number_is_not_a_mobile_number(): void
    {
        foreach (['call me', '0917-ABC-4567', '0917123456', '091712345678', '08171234567', '(049) 501-2233', '@SUM(1+1)'] as $phone) {
            $this->postJson('/api/v1/applicants', $this->applicantPayload(['contact_number' => $phone]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('contact_number');
        }

        $this->patchJson('/api/v1/auth/profile', ['mobile_number' => '0917 123'])
            ->assertUnprocessable()->assertJsonValidationErrors('mobile_number');
        $this->patchJson('/api/v1/auth/profile', ['mobile_number' => '09181234567'])->assertOk();
    }

    public function test_a_client_company_keeps_its_landline_as_written(): void
    {
        $id = $this->postJson('/api/v1/clients', [
            'company_name' => 'Tiwi Foods', 'business_type' => 'Food', 'office_address' => 'Laguna',
            'contact_number' => '(049) 501-2233',
        ])->assertCreated()->json('data.id');
        $this->assertSame('(049) 501-2233', ClientCompany::find($id)->contact_number);

        $this->postJson('/api/v1/clients', [
            'company_name' => 'Other Foods', 'business_type' => 'Food', 'office_address' => 'Laguna',
            'contact_number' => 'n/a',
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_number');
    }

    // ------------------------------------------------------------------ names

    public function test_real_names_pass_and_digits_or_formulas_do_not(): void
    {
        foreach (["Ma. Teresa", "O'Neil", 'Santos-Reyes', 'Ñoño', 'Dela Cruz', 'José'] as $i => $name) {
            $this->postJson('/api/v1/applicants', $this->applicantPayload([
                'first_name' => $name,
                'last_name' => ['Abad', 'Bautista', 'Cruz', 'Dizon', 'Estrada', 'Flores'][$i],
            ]))->assertCreated();
        }

        foreach (['Juan2', '=HYPERLINK("x")', '<b>Ana</b>', ' '] as $name) {
            $this->postJson('/api/v1/applicants', $this->applicantPayload(['first_name' => $name]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('first_name');
        }
    }

    // ---------------------------------------------------------------- emails

    public function test_an_email_needs_a_real_domain(): void
    {
        $this->postJson('/api/v1/applicants', $this->applicantPayload(['email' => 'juan@b']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/applicants', $this->applicantPayload(['email' => 'juan.dc@example.com']))
            ->assertCreated();
    }

    // ---------------------------------------------------------------- numbers

    public function test_measurements_take_numbers_with_up_to_two_decimals(): void
    {
        $id = $this->postJson('/api/v1/applicants', $this->applicantPayload(['height_cm' => 160.55]))
            ->assertCreated()->json('data.id');
        $this->assertEquals(160.55, (float) Applicant::find($id)->height_cm);

        foreach ([['height_cm', 'tall'], ['height_cm', '1e2'], ['height_cm', 160.555], ['distance_km', -1], ['communication_rating', 6]] as [$field, $value]) {
            $this->postJson('/api/v1/applicants', $this->applicantPayload([$field => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_counts_are_whole_numbers_in_range(): void
    {
        foreach (['five', 2.5, 0, 1001] as $workers) {
            $this->postJson('/api/v1/job-requests', $this->requestPayload(['workers_needed' => $workers]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('workers_needed');
        }
    }

    // ------------------------------------------- ranges with an optional low end

    public function test_a_maximum_age_on_its_own_is_accepted(): void
    {
        // Refused before: `gte:age_min` failed whenever the minimum was blank.
        $this->postJson('/api/v1/job-requests', $this->requestPayload(['age_max' => 35]))->assertCreated();

        $this->postJson('/api/v1/job-requests', $this->requestPayload(['age_min' => 40, 'age_max' => 35]))
            ->assertUnprocessable()->assertJsonValidationErrors('age_max');
    }

    public function test_an_update_checks_the_maximum_against_the_saved_minimum(): void
    {
        $id = $this->postJson('/api/v1/job-requests', $this->requestPayload(['age_min' => 21]))
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/job-requests/{$id}", ['age_max' => 18])
            ->assertUnprocessable()->assertJsonValidationErrors('age_max');
        $this->patchJson("/api/v1/job-requests/{$id}", ['age_max' => 35])->assertOk();

        $this->patchJson("/api/v1/job-requests/{$id}", ['deployment_deadline' => now()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('deployment_deadline');
    }

    public function test_a_requirement_s_upper_number_on_its_own_is_accepted(): void
    {
        $id = $this->postJson('/api/v1/job-requests', $this->requestPayload())->assertCreated()->json('data.id');
        $save = fn (array $age) => $this->postJson("/api/v1/job-requests/{$id}/criteria", ['criteria' => [
            ['criteria_code' => 'age', 'mandatory_flag' => true] + $age,
            ['criteria_code' => 'experience', 'weight_score' => 30],
        ]]);

        $save(['max_value' => 35])->assertOk();
        $save(['min_value' => 40, 'max_value' => 35])->assertUnprocessable()->assertJsonValidationErrors('criteria.0.max_value');
        $save(['min_value' => 18.125])->assertUnprocessable()->assertJsonValidationErrors('criteria.0.min_value');
        $save(['min_value' => 1e12])->assertUnprocessable()->assertJsonValidationErrors('criteria.0.min_value');

        $company = ClientCompany::first();
        $age = CriteriaCatalog::where('criteria_code', 'age')->value('id');
        $this->putJson("/api/v1/clients/{$company->id}/criteria", ['criteria' => [
            ['criteria_id' => $age, 'max_value' => 35],
        ]])->assertOk();
    }

    // ------------------------------------------------------------- long text

    public function test_free_text_is_refused_with_a_message_before_the_column_overflows(): void
    {
        $this->postJson('/api/v1/applicants', $this->applicantPayload(['remarks' => str_repeat('a', 5001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('remarks');
        $this->postJson('/api/v1/applicants', $this->applicantPayload(['remarks' => str_repeat('a', 5000)]))
            ->assertCreated();
    }

    // -------------------------------------------- same rule on update as create

    public function test_civil_status_on_update_is_one_of_the_listed_values(): void
    {
        $applicant = $this->applicant($this->hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}", ['civil_status' => 'complicated'])
            ->assertUnprocessable()->assertJsonValidationErrors('civil_status');
        $this->patchJson("/api/v1/applicants/{$applicant->id}", ['civil_status' => 'married'])->assertOk();
    }

    public function test_a_certificate_cannot_expire_before_it_was_issued(): void
    {
        $applicant = $this->applicant($this->hr);
        $payload = fn (string $expires) => ['educations' => [], 'experiences' => [], 'skills' => [], 'certifications' => [[
            'certification_name' => 'TESDA NC II', 'issued_at' => '2025-06-01', 'expires_at' => $expires,
        ]]];

        $this->putJson("/api/v1/applicants/{$applicant->id}/qualifications", $payload('2025-01-01'))
            ->assertUnprocessable()->assertJsonValidationErrors('certifications.0.expires_at');
        $this->putJson("/api/v1/applicants/{$applicant->id}/qualifications", $payload('2028-06-01'))->assertOk();
    }

    public function test_months_of_experience_must_be_given_as_a_whole_number(): void
    {
        $applicant = $this->applicant($this->hr);
        $payload = fn ($months) => ['educations' => [], 'skills' => [], 'certifications' => [], 'experiences' => [[
            'company_name' => 'Tiwi Foods', 'position_title' => 'Packer', 'months_experience' => $months,
        ]]];

        foreach ([null, 'six', 1.5, -1] as $months) {
            $this->putJson("/api/v1/applicants/{$applicant->id}/qualifications", $payload($months))
                ->assertUnprocessable()->assertJsonValidationErrors('experiences.0.months_experience');
        }
    }

    public function test_a_violation_cannot_be_resolved_before_it_happened(): void
    {
        $employee = $this->deployedEmployee();
        $violationId = $this->postJson("/api/v1/employees/{$employee->id}/violations", [
            'violation_date' => now()->subDays(3)->toDateString(),
            'violation_type' => EmployeeViolation::TYPES[0],
            'description' => 'Late twice this week.',
        ])->assertCreated()->json('data.violation.id');

        $this->putJson("/api/v1/employees/{$employee->id}/violations/{$violationId}", [
            'resolution_date' => now()->subDays(10)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('resolution_date');
        $this->putJson("/api/v1/employees/{$employee->id}/violations/{$violationId}", [
            'resolution_date' => now()->toDateString(),
        ])->assertOk();
    }

    private function deployedEmployee(): Employee
    {
        $department = $this->department($this->clientCompany($this->hr), $this->hr);
        $request = $this->jobRequest($department, $this->hr, ['workers_needed' => 10]);
        $applicant = $this->applicant($this->hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $this->hr, 'all');
        $this->completeTraining($applicant, $this->hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->subMonth()->toDateString(),
        ], $this->hr)->employee;
    }
}
