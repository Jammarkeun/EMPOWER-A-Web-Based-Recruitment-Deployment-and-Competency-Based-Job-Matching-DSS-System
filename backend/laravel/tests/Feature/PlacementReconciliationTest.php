<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicationStatusHistory;
use App\Models\AuditLog;
use App\Models\Endorsement;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concurrency\ThrowawayDatabase;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * empower:reconcile-placement-status corrects only what the database itself
 * proves, lists the rest, and changes nothing without --apply and a named
 * staff account.
 *
 * The cast mirrors the drift found in the demo database on 2026-10-30: two
 * workers deployed through the real workflow whose status was later put back
 * to "approved" outside it, and one applicant walked to "active" by hand with
 * no deployment ever recorded.
 */
class PlacementReconciliationTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    private Applicant $stale;
    private Applicant $orphan;
    private Applicant $noHistory;
    private Applicant $separated;

    /** As FilterAccuracyTest: only SQLite or the throwaway MariaDB database. */
    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            ThrowawayDatabase::assert();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoClients();

        // Deployed properly, then reset to "approved" behind the workflow's
        // back and endorsed again on the way.
        $this->stale = $this->placeEmployee($this->deptA, 'Stella', 'Stale')->applicant;
        $this->stale->forceFill(['current_status' => 'approved'])->save();
        Endorsement::create([
            'applicant_id' => $this->stale->id,
            'endorsed_by' => $this->hr->id,
            'endorsed_at' => now(),
        ]);

        // "Active" by hand, with the history showing where it came from.
        $this->orphan = $this->applicant($this->hr, ['first_name' => 'Oscar', 'last_name' => 'Orphan', 'current_status' => 'active']);
        foreach ([['client_evaluation', 'approved'], ['approved', 'deployed'], ['deployed', 'active']] as $i => [$from, $to]) {
            ApplicationStatusHistory::create([
                'applicant_id' => $this->orphan->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $this->hr->id,
                'changed_at' => now()->subDays(10)->addMinutes($i),
            ]);
        }

        // "Active" with nothing to say how: not ours to guess.
        $this->noHistory = $this->applicant($this->hr, ['first_name' => 'Nina', 'last_name' => 'Nohistory', 'current_status' => 'active']);

        // Says resigned while the employment record says working: which is
        // wrong needs a person.
        $this->separated = $this->placeEmployee($this->deptB, 'Sam', 'Separated')->applicant;
        $this->separated->forceFill(['current_status' => 'resigned'])->save();
    }

    private function statuses(): array
    {
        return Applicant::query()->orderBy('id')->pluck('current_status', 'id')->all();
    }

    public function test_a_dry_run_lists_every_disagreement_and_changes_nothing(): void
    {
        $before = $this->statuses();
        $history = ApplicationStatusHistory::count();

        $this->artisan('empower:reconcile-placement-status')
            ->expectsOutputToContain('Would correct')
            ->expectsOutputToContain($this->stale->applicant_code)
            ->expectsOutputToContain($this->orphan->applicant_code)
            ->expectsOutputToContain('Left for a person to decide')
            ->expectsOutputToContain($this->noHistory->applicant_code)
            ->expectsOutputToContain($this->separated->applicant_code)
            ->assertSuccessful();

        $this->assertSame($before, $this->statuses());
        $this->assertSame($history, ApplicationStatusHistory::count());
        $this->assertNull(Endorsement::where('applicant_id', $this->stale->id)->value('ended_at'));
    }

    public function test_apply_corrects_from_the_evidence_and_records_who_and_why(): void
    {
        $deployment = Employee::where('applicant_id', $this->stale->id)->first()->deployments()->first();

        $this->artisan('empower:reconcile-placement-status', ['--apply' => true, '--actor' => $this->hr->email])
            ->expectsOutputToContain('2 of 2 correction(s) written.')
            ->assertSuccessful();

        // Placed: the employment record wins.
        $this->assertSame('active', $this->stale->fresh()->current_status);
        $entry = ApplicationStatusHistory::where('applicant_id', $this->stale->id)->latest('id')->first();
        $this->assertSame(['approved', 'active', $this->hr->id], [$entry->from_status, $entry->to_status, $entry->changed_by]);
        $this->assertStringContainsString($deployment->deployment_code, $entry->reason);

        $endorsement = Endorsement::where('applicant_id', $this->stale->id)->first();
        $this->assertNotNull($endorsement->ended_at);
        $this->assertSame(['deployed', $deployment->id], [$endorsement->end_reason, $endorsement->deployment_id]);

        // Never placed: back to the status before the hand-made move.
        $this->assertSame('approved', $this->orphan->fresh()->current_status);
        $entry = ApplicationStatusHistory::where('applicant_id', $this->orphan->id)->latest('id')->first();
        $this->assertSame(['active', 'approved'], [$entry->from_status, $entry->to_status]);
        $this->assertStringContainsString('without a deployment being recorded', $entry->reason);

        // Unclear: untouched.
        $this->assertSame('active', $this->noHistory->fresh()->current_status);
        $this->assertSame('resigned', $this->separated->fresh()->current_status);

        $this->assertSame(2, AuditLog::where('module_key', 'applicants')
            ->where('actor_user_id', $this->hr->id)
            ->whereIn('record_id', [$this->stale->id, $this->orphan->id])
            ->count());

        // The list no longer flags the corrected worker.
        $this->actAs($this->hr);
        $row = collect($this->getJson('/api/v1/applicants?current_status=deployed')->assertOk()->json('data'))
            ->firstWhere('id', $this->stale->id);
        $this->assertNull($row['placement']['mismatch']);

        // A second run finds nothing more to correct.
        $this->artisan('empower:reconcile-placement-status', ['--apply' => true, '--actor' => $this->hr->email])
            ->doesntExpectOutputToContain('Correcting:')
            ->assertSuccessful();
    }

    public function test_apply_needs_an_active_staff_account(): void
    {
        $before = $this->statuses();
        $portal = User::factory()->create(['email' => 'someone@example.com', 'user_type' => 'applicant']);

        $this->artisan('empower:reconcile-placement-status', ['--apply' => true])->assertFailed();
        $this->artisan('empower:reconcile-placement-status', ['--apply' => true, '--actor' => 'nobody@example.com'])->assertFailed();
        $this->artisan('empower:reconcile-placement-status', ['--apply' => true, '--actor' => $portal->email])->assertFailed();

        $this->assertSame($before, $this->statuses());
    }
}
