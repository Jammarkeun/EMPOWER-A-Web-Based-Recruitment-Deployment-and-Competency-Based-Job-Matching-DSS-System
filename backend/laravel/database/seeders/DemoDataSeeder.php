<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\ApplicantCertification;
use App\Models\ApplicantEducation;
use App\Models\ApplicantExperience;
use App\Models\ApplicantRequirement;
use App\Models\ApplicantSkill;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\CriteriaCatalog;
use App\Models\JobRequest;
use App\Models\RequestCriteria;
use App\Models\RequirementType;
use App\Models\User;
use App\Services\FolderCategoryService;
use App\Services\ReferenceCodeService;
use Illuminate\Database\Seeder;

/**
 * Representative data for demonstration and user acceptance testing.
 *
 * Models the agency's current live account, Best Tiwi Food Products Corporation,
 * with an open production helper request and a spread of applicants at different
 * stages so every folder category and recommendation band is visible on screen.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $hr = User::where('email', 'hr@cdemanpower.local')->firstOrFail();
        $codes = app(ReferenceCodeService::class);
        $folders = app(FolderCategoryService::class);

        $company = ClientCompany::updateOrCreate(
            ['company_name' => 'Best Tiwi Food Products Corporation'],
            [
                'company_code' => $codes->client(),
                'business_type' => 'Food Manufacturing',
                'contact_person' => 'Ma. Elena Bautista',
                'contact_number' => '(049) 501-2233',
                'email' => 'hr@besttiwifoods.com.ph',
                'office_address' => 'Brgy. Pagsawitan, Sta. Cruz, Laguna',
                'status' => 'active',
                'created_by' => $hr->id,
            ]
        );

        $departmentNames = [
            'CHOCO' => 'Chocolate Department',
            'CANDY' => 'Candy Department',
            'PACK' => 'Packaging',
            'WHSE' => 'Warehouse',
            'PROD' => 'Production',
            'QC' => 'Quality Control',
        ];

        $departments = [];
        foreach ($departmentNames as $code => $name) {
            $departments[$code] = ClientDepartment::updateOrCreate(
                ['client_company_id' => $company->id, 'department_code' => $code],
                ['department_name' => $name, 'status' => 'active', 'created_by' => $hr->id]
            );
        }

        $request = JobRequest::updateOrCreate(
            ['request_code' => 'JR-'.now()->year.'-00001'],
            [
                'client_company_id' => $company->id,
                'client_department_id' => $departments['PROD']->id,
                'position_title' => 'Production Helper',
                'required_education' => 'High School Graduate',
                'required_experience_months' => 6,
                'required_certifications' => 'Food Safety NC II',
                'gender_preference' => 'any',
                'age_min' => 18,
                'age_max' => 35,
                'physical_requirement' => 'Physically fit; able to lift 15 kg and stand for an 8-hour shift.',
                'availability_requirement' => 'Can start within 7 days',
                'workers_needed' => 15,
                'date_requested' => now()->subDays(6)->toDateString(),
                'deployment_deadline' => now()->addDays(14)->toDateString(),
                'request_status' => 'open',
                'request_source' => 'email',
                'created_by' => $hr->id,
            ]
        );

        $this->attachCriteria($request, $hr);
        $this->seedApplicants($hr, $codes, $folders);
    }

    /**
     * A weighting that sums to 100 across the scored criteria, with age as a
     * gate. Mirrors the example weights the agency gave during the interview.
     */
    private function attachCriteria(JobRequest $request, User $hr): void
    {
        $catalog = CriteriaCatalog::pluck('id', 'criteria_code');

        $configuration = [
            ['education', false, 15, ['expected_value' => 'high_school']],
            ['experience', false, 20, ['min_value' => 0, 'max_value' => 36]],
            ['certifications', false, 10, ['expected_value' => 'Food Safety NC II, Food Handling NC II']],
            ['distance', false, 5, ['min_value' => 0, 'max_value' => 40]],
            ['availability', false, 15, ['min_value' => 0, 'max_value' => 30]],
            ['communication', false, 20, ['rubric_json' => ['excellent' => 1, 'good' => 0.8, 'fair' => 0.5, 'poor' => 0.2]]],
            ['reliability', false, 15, ['rubric_json' => ['excellent' => 1, 'good' => 0.8, 'fair' => 0.5, 'poor' => 0.2]]],
            ['age', true, 0, ['min_value' => 18, 'max_value' => 35]],
        ];

        foreach ($configuration as [$code, $mandatory, $weight, $extra]) {
            if (! isset($catalog[$code])) {
                continue;
            }

            RequestCriteria::updateOrCreate(
                ['job_request_id' => $request->id, 'criteria_id' => $catalog[$code]],
                array_merge([
                    'mandatory_flag' => $mandatory,
                    'weight_score' => $weight,
                    'created_by' => $hr->id,
                ], $extra)
            );
        }
    }

    /**
     * Applicants spread across the lifecycle: one deployment-ready, one awaiting
     * medicals, and one still incomplete.
     */
    private function seedApplicants(User $hr, ReferenceCodeService $codes, FolderCategoryService $folders): void
    {
        $people = [
            [
                'first_name' => 'Maria Cristina', 'last_name' => 'Santos', 'sex' => 'female',
                'birth_date' => now()->subYears(24)->toDateString(),
                'address' => 'Brgy. Bubukal, Sta. Cruz, Laguna',
                'distance' => 3.4, 'communication' => 'excellent', 'reliability' => 'excellent',
                'education' => ['college_graduate', 'Laguna State Polytechnic University', 'BS Food Technology', 2023],
                'experience' => ['Golden Harvest Foods Inc.', 'Production Operator', 18],
                'certification' => 'Food Safety NC II',
                'status' => 'ready_for_deployment', 'documents' => 'all',
            ],
            [
                'first_name' => 'Joshua', 'last_name' => 'Ramirez', 'sex' => 'male',
                'birth_date' => now()->subYears(28)->toDateString(),
                'address' => 'Brgy. Calios, Sta. Cruz, Laguna',
                'distance' => 8.1, 'communication' => 'good', 'reliability' => 'good',
                'education' => ['high_school', 'Sta. Cruz National High School', null, 2016],
                'experience' => ['Laguna Packaging Corp.', 'Warehouse Helper', 30],
                'certification' => null,
                'status' => 'pending_final_requirements', 'documents' => 'primary',
            ],
            [
                'first_name' => 'Angelica', 'last_name' => 'Dela Peña', 'sex' => 'female',
                'birth_date' => now()->subYears(21)->toDateString(),
                'address' => 'Brgy. Pagsawitan, Sta. Cruz, Laguna',
                'distance' => 5.2, 'communication' => 'fair', 'reliability' => 'good',
                'education' => ['senior_high_school', 'Pedro Guevara Memorial NHS', 'TVL - Food Processing', 2022],
                'experience' => null,
                'certification' => 'Food Handling NC II',
                'status' => 'initial_screening', 'documents' => 'partial',
            ],
        ];

        $ratingToNumber = ['excellent' => 5.0, 'good' => 4.0, 'fair' => 3.0, 'poor' => 2.0];

        foreach ($people as $person) {
            $applicant = Applicant::updateOrCreate(
                ['first_name' => $person['first_name'], 'last_name' => $person['last_name']],
                [
                    'applicant_code' => $codes->applicant(),
                    'source_channel' => 'walk_in',
                    'sex' => $person['sex'],
                    'birth_date' => $person['birth_date'],
                    'civil_status' => 'single',
                    'nationality' => 'Filipino',
                    'contact_number' => '09'.random_int(100000000, 999999999),
                    'present_address' => $person['address'],
                    'preferred_position' => 'Production Helper',
                    'availability_date' => now()->addDays(random_int(0, 10))->toDateString(),
                    'distance_km' => $person['distance'],
                    'communication_rating' => $ratingToNumber[$person['communication']],
                    'reliability_rating' => $ratingToNumber[$person['reliability']],
                    'application_date' => now()->subDays(random_int(3, 20))->toDateString(),
                    'created_by' => $hr->id,
                ]
            );

            [$level, $school, $course, $year] = $person['education'];
            ApplicantEducation::updateOrCreate(
                ['applicant_id' => $applicant->id, 'school_name' => $school],
                ['education_level' => $level, 'course_program' => $course, 'graduation_year' => $year]
            );

            if ($person['experience']) {
                [$employer, $position, $months] = $person['experience'];
                ApplicantExperience::updateOrCreate(
                    ['applicant_id' => $applicant->id, 'company_name' => $employer],
                    ['position_title' => $position, 'months_experience' => $months]
                );
            }

            if ($person['certification']) {
                ApplicantCertification::updateOrCreate(
                    ['applicant_id' => $applicant->id, 'certification_name' => $person['certification']],
                    ['issuer' => 'TESDA', 'issued_at' => now()->subYears(2), 'expires_at' => now()->addYears(3)]
                );
            }

            ApplicantSkill::updateOrCreate(
                ['applicant_id' => $applicant->id, 'skill_name' => 'Food Handling'],
                ['proficiency_level' => 'intermediate']
            );

            $this->seedRequirements($applicant, $hr, $person['documents']);

            // Folder category is derived, then the status is set to match the
            // scenario. Written directly here because the seeder is constructing
            // a starting state rather than performing a business action.
            $folders->recalculate($applicant);
            $applicant->forceFill(['current_status' => $person['status']])->save();
        }
    }

    private function seedRequirements(Applicant $applicant, User $hr, string $completeness): void
    {
        $types = RequirementType::active()->get();

        foreach ($types as $type) {
            $isPrimary = $type->requirement_group === 'primary';

            $status = match ($completeness) {
                'all' => 'verified',
                'primary' => $isPrimary ? 'verified' : 'missing',
                default => $type->requirement_code === 'resume' ? 'verified' : 'missing',
            };

            ApplicantRequirement::updateOrCreate(
                ['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id],
                [
                    'status' => $status,
                    'submitted_at' => $status === 'verified' ? now()->subDays(2) : null,
                    'verified_at' => $status === 'verified' ? now()->subDay() : null,
                    'verified_by' => $status === 'verified' ? $hr->id : null,
                    'expiry_date' => $status === 'verified' && $type->has_expiry
                        ? now()->addMonths(6)->toDateString()
                        : null,
                ]
            );
        }
    }
}
