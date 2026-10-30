<?php

namespace Tests\Support;

use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\Employee;
use App\Models\User;
use App\Services\CoordinatorAssignmentService;
use App\Services\DeploymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * Two clients, each with a department, for coordinator tests.
 *
 * Kept apart from CoordinatorTest's own private helpers so the new incident
 * and requisition tests can share them without that file changing.
 */
trait CoordinatorFixtures
{
    use SeedsDomainData;

    protected User $admin;
    protected User $hr;
    protected ClientCompany $clientA;
    protected ClientCompany $clientB;
    protected ClientDepartment $deptA;
    protected ClientDepartment $deptB;

    protected function setUpTwoClients(): void
    {
        $this->seedReferenceData();

        $this->admin = $this->adminUser();
        $this->hr = $this->hrUser();

        $this->clientA = $this->clientCompany($this->hr, ['company_name' => 'Best Tiwi Food Products Corporation']);
        $this->clientB = $this->clientCompany($this->hr, ['company_name' => 'Client B Corporation']);
        $this->deptA = $this->department($this->clientA, $this->hr, ['department_name' => 'Loading Department', 'department_code' => 'LOAD']);
        $this->deptB = $this->department($this->clientB, $this->hr, ['department_name' => 'Assembly', 'department_code' => 'ASSY']);
    }

    protected function coordinatorFor(ClientCompany $company): User
    {
        $user = User::factory()->create([
            'email' => 'coordinator.'.uniqid().'@cdemanpower.local',
            'user_type' => 'coordinator',
            'password' => Hash::make('Coordinator-Pass-2026!'),
        ]);
        $user->assignRole('coordinator');
        app(CoordinatorAssignmentService::class)->assign($user, $company, $this->admin);

        return $user;
    }

    protected function placeEmployee(ClientDepartment $department, string $first, string $last): Employee
    {
        $request = $this->jobRequest($department, $this->hr, ['workers_needed' => 10]);
        $applicant = $this->applicant($this->hr, [
            'first_name' => $first,
            'last_name' => $last,
            'current_status' => 'ready_for_deployment',
        ]);
        $this->verifyRequirements($applicant, $this->hr, 'all');
        $this->completeTraining($applicant, $this->hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->subMonth()->toDateString(),
            'supervisor_name' => 'Line Supervisor',
        ], $this->hr)->employee;
    }

    protected function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    protected function pdfUpload(string $name = 'evidence.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function scriptUpload(string $name = 'evidence.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, "<?php echo 'hello';");

        return new UploadedFile($path, $name, null, null, true);
    }
}
