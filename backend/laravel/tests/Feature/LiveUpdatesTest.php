<?php

namespace Tests\Feature;

use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Open screens refresh themselves when this marker moves, so it has to move on
 * every change worth showing and stay still otherwise.
 */
class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private function version(): string
    {
        return $this->getJson('/api/v1/live')->assertOk()->json('data.version');
    }

    public function test_the_marker_moves_on_a_change_and_on_a_new_notification_only(): void
    {
        $this->seedReferenceData();
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $first = $this->version();
        $this->assertSame($first, $this->version(), 'Asking again changes nothing.');

        app(AuditService::class)->record('update', 'applicants');
        $afterChange = $this->version();
        $this->assertNotSame($first, $afterChange);

        $this->travel(1)->seconds();
        $hr->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'data' => ['category' => 'general', 'title' => 't', 'body' => 'b', 'link' => null],
        ]);
        $this->assertNotSame($afterChange, $this->version());
    }

    public function test_signing_in_and_out_moves_only_the_activity_marker(): void
    {
        $this->seedReferenceData();
        $hr = $this->hrUser();
        $other = $this->hrUser();
        Sanctum::actingAs($hr);
        $markers = fn () => $this->getJson('/api/v1/live')->assertOk()->json('data');

        $before = $markers();

        // Somebody else signs in, mistypes a password, and signs out: all
        // audited, none of it a change to any record on anybody's screen.
        $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'not-the-password'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
        app(AuditService::class)->record('logout', 'auth', actor: $other);
        app(AuditService::class)->record('export', 'reports');
        Sanctum::actingAs($hr);

        $afterSignIns = $markers();
        $this->assertSame($before['version'], $afterSignIns['version'], 'Open screens must not refetch because somebody signed in.');
        $this->assertNotSame($before['activity'], $afterSignIns['activity'], 'The sign-in screens still hear about it.');

        // A real change moves both.
        app(AuditService::class)->record('update', 'applicants');
        $afterChange = $markers();
        $this->assertNotSame($afterSignIns['version'], $afterChange['version']);
        $this->assertNotSame($afterSignIns['activity'], $afterChange['activity']);
    }

    public function test_the_marker_needs_a_sign_in(): void
    {
        $this->getJson('/api/v1/live')->assertUnauthorized();
    }
}
