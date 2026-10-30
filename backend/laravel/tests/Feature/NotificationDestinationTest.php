<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The unread count says where each notification leads, so the sidebar can
 * show the number on the page it opens - every page, new ones included.
 */
class NotificationDestinationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    public function test_unread_notifications_are_grouped_by_the_page_they_open(): void
    {
        $this->seedReferenceData();
        $hr = $this->hrUser();

        $notify = fn (?string $link, string $category, bool $read = false) => $hr->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'data' => ['category' => $category, 'title' => 't', 'body' => 'b', 'link' => $link],
            'read_at' => $read ? now() : null,
        ]);

        $notify('/requisitions/5', 'coordination');
        $notify('/requisitions/6', 'coordination');
        $notify('/incident-reports/2', 'coordination');
        $notify('/applicants?expiring=1', 'requirements');
        $notify('/coordinator/incidents/3?tab=notes', 'coordination');
        $notify(null, 'general');
        $notify('/requisitions/7', 'coordination', read: true);

        Sanctum::actingAs($hr);
        $data = $this->getJson('/api/v1/notifications/unread-count')->assertOk()->json('data');

        $this->assertSame(6, $data['unread_count']);
        // The category breakdown is unchanged.
        $this->assertSame(4, $data['by_category']['coordination']);

        $byPath = collect($data['by_destination'])->mapWithKeys(fn ($e) => [($e['path'] ?? 'none').'|'.$e['category'] => $e['count']]);
        $this->assertSame(2, $byPath['/requisitions|coordination']);
        $this->assertSame(1, $byPath['/incident-reports|coordination']);
        $this->assertSame(1, $byPath['/applicants|requirements']);
        $this->assertSame(1, $byPath['/coordinator/incidents|coordination']);
        $this->assertSame(1, $byPath['none|general']);
        // The same notifications, the same total.
        $this->assertSame(6, collect($data['by_destination'])->sum('count'));
    }

    public function test_opening_a_page_marks_read_exactly_what_was_counted_on_it_and_only_your_own(): void
    {
        $this->seedReferenceData();
        $hr = $this->hrUser();
        $colleague = $this->hrUser();

        $notify = fn ($user, ?string $link, string $category) => $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'data' => ['category' => $category, 'title' => 't', 'body' => 'b', 'link' => $link],
        ]);

        $notify($hr, '/requisitions/5', 'coordination');
        $notify($hr, '/requisitions/6', 'coordination');
        $notify($hr, '/violation-reports/1', 'coordination');
        $notify($colleague, '/requisitions/5', 'coordination');

        Sanctum::actingAs($hr);
        $this->postJson('/api/v1/notifications/read-destinations', [
            'destinations' => [['path' => '/requisitions', 'category' => 'coordination']],
        ])->assertOk()->assertJsonPath('data.marked', 2);

        $data = $this->getJson('/api/v1/notifications/unread-count')->json('data');
        $this->assertSame(1, $data['unread_count']);
        $this->assertSame('/violation-reports', $data['by_destination'][0]['path']);

        // A colleague's notifications about the same requisition are theirs.
        $this->assertSame(1, $colleague->unreadNotifications()->count());

        $this->postJson('/api/v1/notifications/read-destinations', ['destinations' => []])->assertStatus(422);
    }
}
