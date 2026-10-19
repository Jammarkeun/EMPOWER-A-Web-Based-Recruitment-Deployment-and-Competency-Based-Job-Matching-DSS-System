<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The audit trail in words (audit R-28): who did what to which record, field
 * by field, without claiming more than an entry records and without showing
 * credentials.
 */
class AuditTrailReadabilityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->admin = $this->adminUser();
    }

    private function entries(array $query = []): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/v1/audit-logs?'.http_build_query($query + ['per_page' => 100]))->assertOk()->json('data');
    }

    public function test_a_status_change_reads_field_by_field_against_a_named_record(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['first_name' => 'Maria', 'last_name' => 'Santos']);

        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'initial_screening', 'reason' => 'Resume received.'])
            ->assertOk();

        $entry = collect($this->entries(['record_type' => 'Applicant', 'record_id' => $applicant->id]))
            ->firstWhere('action_type', 'update');

        $this->assertSame('changes', $entry['detail_level']);
        $this->assertSame($applicant->applicant_code.' · Maria Santos', $entry['record']['label']);
        $this->assertSame($hr->full_name, $entry['actor']['name']);

        $status = collect($entry['changes'])->firstWhere('field', 'current_status');
        $this->assertSame(['changed', 'applied', 'initial_screening'], [$status['kind'], $status['before'], $status['after']]);

        // Only the new value of the reason was recorded, so it is not called a change.
        $reason = collect($entry['changes'])->firstWhere('field', 'reason');
        $this->assertSame(['recorded', null, 'Resume received.'], [$reason['kind'], $reason['before'], $reason['after']]);
    }

    public function test_an_entry_without_values_is_shown_as_activity_only(): void
    {
        app(AuditService::class)->record('login', 'auth', User::class, $this->admin->id, actor: $this->admin);

        $entry = collect($this->entries(['action_type' => 'login']))->first();

        $this->assertSame('activity', $entry['detail_level']);
        $this->assertSame([], $entry['changes']);
    }

    public function test_credentials_are_never_shown_even_in_old_entries(): void
    {
        // As an entry written before nested and differently named keys were redacted.
        AuditLog::create([
            'actor_user_id' => $this->admin->id,
            'action_type' => 'create',
            'module_key' => 'users',
            'record_type' => 'User',
            'record_id' => $this->admin->id,
            'new_values_json' => [
                'temporary_password' => 'Plain-Text-123',
                'settings' => ['api_token' => 'abc', 'theme' => 'dark'],
            ],
        ]);

        $changes = collect(collect($this->entries(['module_key' => 'users']))->first()['changes'])->keyBy('field');

        $this->assertSame('[redacted]', $changes['temporary_password']['after']);
        $this->assertStringNotContainsString('abc', $changes['settings']['after']);
        $this->assertStringContainsString('dark', $changes['settings']['after']);
    }

    public function test_credentials_are_redacted_at_any_depth_when_recorded(): void
    {
        $log = app(AuditService::class)->record('update', 'users', User::class, $this->admin->id, null, [
            'reset_token' => 'secret-value',
            'nested' => ['password' => 'x', 'kept' => 'visible'],
        ], actor: $this->admin);

        $this->assertSame('[redacted]', $log->new_values_json['reset_token']);
        $this->assertSame('[redacted]', $log->new_values_json['nested']['password']);
        $this->assertSame('visible', $log->new_values_json['nested']['kept']);
    }

    public function test_filters_are_offered_from_what_the_trail_holds_and_only_to_administrators(): void
    {
        app(AuditService::class)->record('export', 'reports', actor: $this->admin);

        Sanctum::actingAs($this->admin);
        $facets = $this->getJson('/api/v1/audit-logs/facets')->assertOk()->json('data');
        $this->assertContains('export', $facets['action_types']);
        $this->assertContains($this->admin->id, array_column($facets['actors'], 'id'));

        $this->assertNotEmpty($this->entries(['actor_user_id' => $this->admin->id, 'date_to' => now()->toDateString()]));

        Sanctum::actingAs($this->hrUser());
        $this->getJson('/api/v1/audit-logs/facets')->assertForbidden();
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}
