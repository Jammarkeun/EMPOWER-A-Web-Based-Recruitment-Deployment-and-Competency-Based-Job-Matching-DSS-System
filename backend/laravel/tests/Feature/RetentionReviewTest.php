<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\ApplicationStatusHistory;
use App\Models\AuditLog;
use App\Models\RequirementType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The retention review list (audit FN-12, R-27): records past CDE's retention
 * estimates, offered to a person. Nothing on it is archived or deleted by the
 * system.
 */
class RetentionReviewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        config([
            'empower.retention.unhired_applicant_months' => 3,
            'empower.retention.legal_document_years' => 5,
        ]);
        $this->hr = $this->hrUser();
        Sanctum::actingAs($this->hr);
    }

    /** An applicant whose application, and last status change, are this old. */
    private function idleApplicant(string $name, int $monthsIdle, string $status = 'incomplete_requirements'): Applicant
    {
        $applicant = $this->applicant($this->hr, [
            'first_name' => $name,
            'current_status' => $status,
            'application_date' => now()->subMonths($monthsIdle + 1)->toDateString(),
        ]);

        ApplicationStatusHistory::create([
            'applicant_id' => $applicant->id,
            'from_status' => 'initial_screening',
            'to_status' => $status,
            'changed_by' => $this->hr->id,
            'changed_at' => now()->subMonths($monthsIdle),
        ]);

        return $applicant;
    }

    /** A record closed this many years ago, with a document still on file. */
    private function closedRecord(string $name, int $yearsAgo, string $status = 'resigned', bool $dated = true): Applicant
    {
        $applicant = $this->applicant($this->hr, ['first_name' => $name, 'current_status' => $status]);

        ApplicantRequirement::create([
            'applicant_id' => $applicant->id,
            'requirement_type_id' => RequirementType::query()->value('id'),
            'status' => 'verified',
            'file_path' => 'requirements/'.$applicant->id.'/nbi.pdf',
        ]);

        if ($dated) {
            ApplicationStatusHistory::create([
                'applicant_id' => $applicant->id,
                'from_status' => 'active',
                'to_status' => $status,
                'changed_by' => $this->hr->id,
                'changed_at' => now()->subYears($yearsAgo),
            ]);
        }

        return $applicant;
    }

    private function listed(?string $category = null): array
    {
        return $this->getJson('/api/v1/retention-reviews'.($category ? "?category={$category}" : ''))
            ->assertOk()
            ->json('data');
    }

    public function test_only_unhired_applicants_idle_past_the_window_are_listed(): void
    {
        $idle = $this->idleApplicant('Idle', 4);
        $this->idleApplicant('Recent', 1);
        $this->idleApplicant('Employed', 8, 'active');
        $this->idleApplicant('Closed', 8, 'archived');

        $data = $this->listed('unhired_applicant');

        $this->assertSame([$idle->id], array_column(array_column($data['rows'], 'applicant'), 'id'));
        $this->assertSame(['retain', 'archive'], $data['rows'][0]['actions']);
        $this->assertStringContainsString('3 months', $data['rows'][0]['reason']);
        $this->assertSame(3, $data['policy']['unhired_applicant_months']);
    }

    public function test_recent_activity_on_documents_keeps_an_applicant_off_the_list(): void
    {
        $applicant = $this->idleApplicant('Uploading', 5);

        ApplicantRequirement::create([
            'applicant_id' => $applicant->id,
            'requirement_type_id' => RequirementType::query()->value('id'),
            'status' => 'submitted',
        ]);

        $this->assertSame([], $this->listed('unhired_applicant')['rows']);
    }

    public function test_closed_records_are_listed_once_their_documents_pass_the_retention_period(): void
    {
        $old = $this->closedRecord('Old', 6);
        $this->closedRecord('Recent', 2, 'terminated');
        $this->closedRecord('Undated', 9, 'resigned', dated: false);

        $data = $this->listed('closed_record_documents');

        $this->assertSame([$old->id], array_column(array_column($data['rows'], 'applicant'), 'id'));
        $this->assertSame(['retain'], $data['rows'][0]['actions'], 'Disposal is never offered.');
        $this->assertSame(1, $data['rows'][0]['documents_on_file']);
        // Not guessed at, but not hidden.
        $this->assertSame(1, $data['undated_closed_records']);
    }

    public function test_a_keep_decision_hides_the_record_until_its_date_and_is_shown_after(): void
    {
        $applicant = $this->idleApplicant('Kept', 4);

        $this->postJson('/api/v1/retention-reviews', [
            'category' => 'unhired_applicant',
            'applicant_id' => $applicant->id,
            'note' => 'Expected back after harvest season.',
            'retain_until' => now()->addMonth()->toDateString(),
        ])->assertCreated();

        $this->assertSame([], $this->listed()['rows']);
        $this->assertTrue(AuditLog::where('record_type', 'RetentionReview')->where('action_type', 'create')->exists());

        $this->travel(32)->days();

        $row = $this->listed()['rows'][0];
        $this->assertSame($applicant->id, $row['applicant']['id']);
        $this->assertSame('Expected back after harvest season.', $row['previous_decision']['note']);
    }

    public function test_decisions_are_refused_for_records_that_are_not_due_or_without_a_reason(): void
    {
        $recent = $this->idleApplicant('Recent', 1);
        $due = $this->idleApplicant('Due', 4);

        $this->postJson('/api/v1/retention-reviews', [
            'category' => 'unhired_applicant',
            'applicant_id' => $recent->id,
            'note' => 'Keep it.',
            'retain_until' => now()->addMonth()->toDateString(),
        ])->assertStatus(409);

        $this->postJson('/api/v1/retention-reviews', [
            'category' => 'unhired_applicant',
            'applicant_id' => $due->id,
            'note' => '',
            'retain_until' => now()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['note', 'retain_until']);
    }

    public function test_listing_and_deciding_never_change_or_delete_a_record(): void
    {
        $applicant = $this->idleApplicant('Untouched', 4);
        $closed = $this->closedRecord('Closed', 6);
        $before = [Applicant::withTrashed()->count(), ApplicantRequirement::count(), $applicant->fresh()->current_status];

        $this->listed();
        $this->postJson('/api/v1/retention-reviews', [
            'category' => 'closed_record_documents',
            'applicant_id' => $closed->id,
            'note' => 'Labour case still open.',
            'retain_until' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $this->assertSame($before, [Applicant::withTrashed()->count(), ApplicantRequirement::count(), $applicant->fresh()->current_status]);
        $this->assertSame('requirements/'.$closed->id.'/nbi.pdf', $closed->requirements()->value('file_path'));
    }

    public function test_the_window_follows_the_setting(): void
    {
        $applicant = $this->idleApplicant('Borderline', 4);

        config(['empower.retention.unhired_applicant_months' => 6]);
        $this->assertSame([], $this->listed('unhired_applicant')['rows']);

        config(['empower.retention.unhired_applicant_months' => 3]);
        $this->assertSame($applicant->id, $this->listed('unhired_applicant')['rows'][0]['applicant']['id']);
    }

    public function test_only_staff_who_keep_the_archive_can_see_or_decide(): void
    {
        $this->idleApplicant('Someone', 4);

        $coordinator = User::factory()->create(['email' => 'c.'.uniqid().'@cdemanpower.local', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        Sanctum::actingAs($coordinator);
        $this->getJson('/api/v1/retention-reviews')->assertForbidden();
        $this->postJson('/api/v1/retention-reviews', [])->assertForbidden();

        $applicant = $this->applicant($this->hr);
        $portal = User::factory()->create([
            'email' => 'p.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'password' => Hash::make('password'),
        ]);
        $portal->assignRole('portal');
        Sanctum::actingAs($portal);
        $this->getJson('/api/v1/retention-reviews')->assertForbidden();
    }
}
