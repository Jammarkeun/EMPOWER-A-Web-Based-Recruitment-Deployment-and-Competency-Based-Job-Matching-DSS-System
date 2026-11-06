<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\Employee;
use App\Models\JobRequest;
use App\Models\RequirementType;
use App\Models\Training;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CoordinatorAssignmentService;
use App\Services\DeploymentService;
use App\Services\ReferenceCodeService;
use App\Services\ScheduleSubmissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic employees and approved schedules for the DTR sample files.
 *
 * The files in docs/09-testing/dtr-samples/ identify workers by these
 * biometrics numbers, at this client, over October 2026. This seeder creates
 * the workers they refer to - none of them a real person - and walks each
 * through the real path: requirements verified, training completed, deployed
 * through DeploymentService, and given a schedule that a coordinator submitted
 * and HR approved (ScheduleSubmissionService). One worker is placed at a
 * second client so the importer's "not placed at this client" check has
 * something to refuse.
 *
 * Development and test databases only: it refuses to run in production, and
 * does nothing if the synthetic client already exists. Run it with
 *
 *   php artisan db:seed --class=DtrSampleSeeder
 *
 * It needs an active administrator and an active HR user to act as.
 */
class DtrSampleSeeder extends Seeder
{
    public const CLIENT = 'DTR Sample Client Co. (Synthetic Test Data)';

    public const OTHER_CLIENT = 'DTR Other Client Inc. (Synthetic Test Data)';

    public const DEPLOYED_ON = '2026-10-01';

    public const MARKER = 'SYNTHETIC DTR TEST DATA - not a real person';

    public const COORDINATOR_EMAIL = 'coordinator.dtrsample@example.test';

    /** Biometrics number => the synthetic worker it belongs to. */
    public const EMPLOYEES = [
        '90001' => ['first' => 'Alma', 'last' => 'Dizon', 'client' => 'main', 'schedule' => 'day'],
        '90002' => ['first' => 'Benjie', 'last' => 'Ocampo', 'client' => 'main', 'schedule' => 'night'],
        '90003' => ['first' => 'Carla', 'last' => 'Mendoza', 'client' => 'main', 'schedule' => 'six_day'],
        '90004' => ['first' => 'Dario', 'last' => 'Villanueva', 'client' => 'main', 'schedule' => 'day'],
        '90005' => ['first' => 'Elena', 'last' => 'Bautista', 'client' => 'other', 'schedule' => 'day'],
    ];

    public const SCHEDULES = [
        'day' => [
            'work_days' => [1, 2, 3, 4, 5], 'shift_start' => '08:00', 'shift_end' => '17:00',
            'break_start' => '12:00', 'break_minutes' => 60,
        ],
        'night' => [
            'work_days' => [1, 2, 3, 4, 5], 'shift_start' => '22:00', 'shift_end' => '06:00',
            'break_start' => null, 'break_minutes' => null,
        ],
        'six_day' => [
            'work_days' => [1, 2, 3, 4, 5, 6], 'shift_start' => '07:00', 'shift_end' => '16:00',
            'break_start' => '11:00', 'break_minutes' => 60,
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DtrSampleSeeder creates synthetic test records and never runs in production.');
        }

        if (ClientCompany::where('company_name', self::CLIENT)->exists()) {
            $this->command?->warn('The synthetic DTR sample client already exists; nothing was changed.');

            return;
        }

        $admin = User::role('admin')->where('is_active', true)->orderBy('id')->first();
        $hr = User::role('hr')->where('is_active', true)->orderBy('id')->first();

        if (! $admin || ! $hr) {
            throw new RuntimeException('DtrSampleSeeder needs an active administrator and an active HR user to act as.');
        }

        // Audit entries name the HR user, as if HR had entered the records.
        Auth::setUser($hr);

        DB::transaction(function () use ($admin, $hr) {
            $main = $this->client(self::CLIENT, 'Packing Line (Synthetic)', $hr);
            $other = $this->client(self::OTHER_CLIENT, 'Warehouse (Synthetic)', $hr);
            $requests = [
                'main' => $this->jobRequest($main['department'], $hr),
                'other' => $this->jobRequest($other['department'], $hr),
            ];

            $coordinator = User::create([
                'first_name' => 'Synthetic',
                'last_name' => 'Coordinator',
                'email' => self::COORDINATOR_EMAIL,
                // Nobody knows this password; an administrator resets it to sign in.
                'password' => Hash::make(Str::random(40)),
                'user_type' => 'coordinator',
                'is_active' => true,
            ]);
            $coordinator->assignRole('coordinator');
            $assignment = app(CoordinatorAssignmentService::class)->assign($coordinator, $main['company'], $admin, self::MARKER);

            foreach (self::EMPLOYEES as $biometric => $person) {
                $employee = $this->deploy((string) $biometric, $person, $requests[$person['client']], $hr);
                $schedule = self::SCHEDULES[$person['schedule']] + [
                    'effective_from' => self::DEPLOYED_ON,
                    'remarks' => 'Synthetic test schedule',
                ];

                if ($person['client'] === 'main') {
                    // The coordinator writes it; HR approves it.
                    $submissions = app(ScheduleSubmissionService::class);
                    $submission = $submissions->create($assignment, $coordinator, $employee, $schedule + [
                        'source_note' => 'Synthetic schedule for DTR import testing',
                    ]);
                    $submissions->submit($submission, $assignment, $coordinator);
                    $submissions->review($submission->fresh(), $hr, 'approve', 'Synthetic test data');
                } else {
                    // No coordinator at the other client: HR sets it directly.
                    app(AttendanceService::class)->setSchedule($employee, $schedule, $hr);
                }
            }
        });

        $this->command?->info('Synthetic DTR sample client, 5 employees and their approved schedules created.');
    }

    /** @return array{company: ClientCompany, department: ClientDepartment} */
    private function client(string $name, string $department, User $hr): array
    {
        $company = ClientCompany::create([
            'company_code' => app(ReferenceCodeService::class)->client(),
            'company_name' => $name,
            'business_type' => 'Synthetic test client',
            'office_address' => 'Synthetic address - not a real company',
            'status' => 'active',
            'created_by' => $hr->id,
        ]);

        return [
            'company' => $company,
            'department' => ClientDepartment::create([
                'client_company_id' => $company->id,
                'department_code' => 'SYN',
                'department_name' => $department,
                'status' => 'active',
                'created_by' => $hr->id,
            ]),
        ];
    }

    private function jobRequest(ClientDepartment $department, User $hr): JobRequest
    {
        $request = JobRequest::create([
            'request_code' => app(ReferenceCodeService::class)->jobRequest(),
            'client_company_id' => $department->client_company_id,
            'client_department_id' => $department->id,
            'position_title' => 'Packer (Synthetic)',
            'workers_needed' => 10,
            'date_requested' => '2026-09-25',
            'created_by' => $hr->id,
        ]);
        $request->forceFill(['request_status' => 'open'])->save();

        return $request;
    }

    /** One synthetic worker, ready for deployment and deployed. */
    private function deploy(string $biometric, array $person, JobRequest $request, User $hr): Employee
    {
        $applicant = Applicant::create([
            'applicant_code' => app(ReferenceCodeService::class)->applicant(),
            'source_channel' => 'walk_in',
            'first_name' => $person['first'],
            'last_name' => $person['last'],
            'email' => strtolower("{$person['first']}.{$person['last']}.dtrsample@example.test"),
            'sex' => in_array($person['first'], ['Alma', 'Carla', 'Elena'], true) ? 'female' : 'male',
            'birth_date' => '1998-01-15',
            'present_address' => 'Synthetic address - not a real person',
            'application_date' => '2026-09-20',
            'remarks' => self::MARKER,
            'created_by' => $hr->id,
        ]);
        $applicant->forceFill(['current_status' => 'ready_for_deployment'])->save();

        foreach (RequirementType::active()->where('is_required', true)->get() as $type) {
            ApplicantRequirement::updateOrCreate(
                ['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id],
                ['status' => 'verified', 'verified_at' => now(), 'verified_by' => $hr->id]
            );
        }

        $training = Training::create([
            'training_code' => 'TRN-DTRSAMPLE-'.$biometric,
            'training_title' => 'Pre-deployment orientation (synthetic)',
            'training_date' => '2026-09-28',
            'location' => 'Synthetic venue',
            'trainer_name' => 'Synthetic trainer',
            'status' => 'completed',
            'created_by' => $hr->id,
        ]);
        TrainingEnrollment::create([
            'training_id' => $training->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => 'present',
            'completion_status' => 'completed',
        ]);

        return app(DeploymentService::class)->deploy($applicant->fresh(), $request, [
            'deployment_date' => self::DEPLOYED_ON,
            'biometric_number' => $biometric,
            'supervisor_name' => 'Synthetic Supervisor',
        ], $hr)->employee;
    }
}
