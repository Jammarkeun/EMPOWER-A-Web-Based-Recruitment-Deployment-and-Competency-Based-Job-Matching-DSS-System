<?php

namespace Tests\Feature;

use App\Exceptions\InvalidTransitionException;
use App\Services\ApplicantLifecycleService;
use App\Services\FolderCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

class ApplicantLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    /**
     * The central guarantee: an applicant cannot skip the screening and document
     * stages and land straight in a deployable state.
     */
    public function test_applicant_cannot_jump_from_applied_to_deployed(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'applied']);

        $this->expectException(InvalidTransitionException::class);

        app(ApplicantLifecycleService::class)->transition($applicant, 'deployed', $hr);
    }

    public function test_valid_transition_is_recorded_in_history(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'applied']);

        app(ApplicantLifecycleService::class)
            ->transition($applicant, 'initial_screening', $hr, 'Documents received at the office.');

        $this->assertSame('initial_screening', $applicant->fresh()->current_status);

        $this->assertDatabaseHas('application_status_history', [
            'applicant_id' => $applicant->id,
            'from_status' => 'applied',
            'to_status' => 'initial_screening',
            'reason' => 'Documents received at the office.',
            'changed_by' => $hr->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action_type' => 'update',
            'module_key' => 'applicants',
            'record_type' => 'Applicant',
            'record_id' => $applicant->id,
        ]);
    }

    /**
     * Deployment readiness is gated on documents, not on someone's judgement.
     * Without this an applicant reaches a client site with medicals outstanding.
     */
    public function test_cannot_become_deployment_ready_with_outstanding_medicals(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'pending_final_requirements']);
        $this->verifyRequirements($applicant, $hr, 'primary');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Primary and final requirements must both be verified');

        app(ApplicantLifecycleService::class)->transition($applicant, 'ready_for_deployment', $hr);
    }

    public function test_becomes_deployment_ready_once_all_documents_are_verified(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'pending_final_requirements']);
        $this->verifyRequirements($applicant, $hr, 'all');

        app(ApplicantLifecycleService::class)->transition($applicant, 'ready_for_deployment', $hr);

        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);
    }

    public function test_archived_applicant_has_no_further_transitions(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'archived']);

        $this->expectException(InvalidTransitionException::class);

        app(ApplicantLifecycleService::class)->transition($applicant, 'applied', $hr);
    }

    // ------------------------------------------------------------ folder logic

    public function test_folder_three_when_only_a_resume_is_on_file(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'none');

        $this->assertSame('folder_3', app(FolderCategoryService::class)->determine($applicant));
    }

    public function test_folder_two_when_primary_requirements_are_verified(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'primary');

        $this->assertSame('folder_2', app(FolderCategoryService::class)->determine($applicant));
    }

    public function test_folder_one_when_all_requirements_are_verified(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'all');

        $service = app(FolderCategoryService::class);

        $this->assertSame('folder_1', $service->determine($applicant));
        $this->assertTrue($service->explain($applicant)['is_deployment_ready']);
    }

    /**
     * A lapsed police clearance or medical certificate must pull the applicant
     * back out of the deployable folder.
     */
    public function test_expired_document_removes_deployment_readiness(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'all');

        $applicant->requirements()
            ->whereHas('requirementType', fn ($q) => $q->where('requirement_code', 'police_clearance'))
            ->update(['expiry_date' => now()->subDay()]);

        $applicant->unsetRelation('requirements');
        $service = app(FolderCategoryService::class);

        $this->assertSame('folder_3', $service->determine($applicant));
        $this->assertContains('Police Clearance', $service->explain($applicant)['missing_primary']);
    }

    public function test_folder_category_is_persisted_on_recalculation(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'primary');

        app(FolderCategoryService::class)->recalculate($applicant);

        $this->assertSame('folder_2', $applicant->fresh()->folder_category);
    }
}
