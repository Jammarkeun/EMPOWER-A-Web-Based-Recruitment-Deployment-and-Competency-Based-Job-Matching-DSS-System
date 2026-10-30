<?php

namespace Tests\Feature;

use App\Models\EmployeeViolation;
use App\Models\IncidentReport;
use App\Notifications\IncidentReportSubmitted;
use App\Notifications\IncidentReportUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * The coordinator's incident report (CDE meeting, 26 October 2026).
 *
 * "Report lang siya": something that happened on site - everyone in the
 * loading department sent home at 12:30 because the truck would not run -
 * written for the office to read and answer with "case pending" or "case
 * resolved". It names no employee and is never a violation.
 */
class CoordinatorIncidentReportTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoClients();
    }

    private function incident(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Loading department sent home',
            'incident_date' => now()->toDateString(),
            'incident_time' => '12:30',
            'client_department_id' => $this->deptA->id,
            'details' => 'All workers in the loading department were sent home at 12:30 because the delivery truck would not run.',
        ], $overrides);
    }

    public function test_a_coordinator_reports_an_incident_without_naming_an_employee(): void
    {
        Notification::fake();
        $coordinator = $this->coordinatorFor($this->clientA);
        $this->actAs($coordinator);

        $response = $this->postJson('/api/v1/coordinator/incident-reports', $this->incident([
            // Ignored: the client comes from the assignment, never the request.
            'client_company_id' => $this->clientB->id,
            'submit' => true,
        ]))->assertCreated();

        $this->assertSame('submitted', $response->json('data.status'));
        $this->assertSame('12:30', $response->json('data.incident_time'));
        $this->assertSame('Loading Department', $response->json('data.department_name'));
        $this->assertMatchesRegularExpression('/^IR-\d{4}-\d{5}$/', $response->json('data.report_code'));

        $report = IncidentReport::findOrFail($response->json('data.id'));
        $this->assertSame($this->clientA->id, $report->client_company_id);
        $this->assertSame($coordinator->id, $report->reported_by);

        // An incident is not a violation, and nobody's record changed.
        $this->assertSame(0, EmployeeViolation::count());

        Notification::assertSentTo($this->hr, IncidentReportSubmitted::class);
        Notification::assertSentTo($this->admin, IncidentReportSubmitted::class);
        Notification::assertNotSentTo($coordinator, IncidentReportSubmitted::class);
        $this->assertDatabaseHas('audit_logs', [
            'module_key' => 'incident_reports', 'action_type' => 'submit', 'client_company_id' => $this->clientA->id,
        ]);
    }

    public function test_the_form_requires_a_title_date_and_details_and_refuses_a_future_date(): void
    {
        $this->actAs($this->coordinatorFor($this->clientA));

        $this->postJson('/api/v1/coordinator/incident-reports', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'incident_date', 'details']);

        $this->postJson('/api/v1/coordinator/incident-reports', $this->incident([
            'incident_date' => now()->addDay()->toDateString(),
            'incident_time' => '25:99',
        ]))->assertStatus(422)->assertJsonValidationErrors(['incident_date', 'incident_time']);
    }

    public function test_a_coordinator_cannot_file_under_another_clients_department_or_read_another_clients_reports(): void
    {
        $coordinatorA = $this->coordinatorFor($this->clientA);
        $coordinatorB = $this->coordinatorFor($this->clientB);

        $this->actAs($coordinatorA);
        $this->postJson('/api/v1/coordinator/incident-reports', $this->incident([
            'client_department_id' => $this->deptB->id,
        ]))->assertForbidden();
        $this->assertSame(0, IncidentReport::count());

        $id = $this->postJson('/api/v1/coordinator/incident-reports', $this->incident(['submit' => true]))
            ->assertCreated()->json('data.id');

        $this->actAs($coordinatorB);
        $this->getJson("/api/v1/coordinator/incident-reports/{$id}")->assertForbidden();
        $this->postJson("/api/v1/coordinator/incident-reports/{$id}/notes", ['note' => 'Not mine'])->assertForbidden();
        $this->assertSame(0, $this->getJson('/api/v1/coordinator/incident-reports')->json('meta.total'));

        // Nor can a coordinator reach the office's routes.
        $this->getJson('/api/v1/incident-reports')->assertForbidden();
        $this->getJson("/api/v1/incident-reports/{$id}")->assertForbidden();
        $this->postJson("/api/v1/incident-reports/{$id}/notes", ['note' => 'x', 'status' => 'resolved'])->assertForbidden();
    }

    public function test_a_draft_stays_with_its_author_and_a_sent_report_cannot_be_rewritten(): void
    {
        $author = $this->coordinatorFor($this->clientA);
        $colleague = $this->coordinatorFor($this->clientA);

        $this->actAs($author);
        $id = $this->postJson('/api/v1/coordinator/incident-reports', $this->incident())->assertCreated()->json('data.id');

        // Neither a colleague nor the office sees an unsent draft.
        $this->actAs($colleague);
        $this->getJson("/api/v1/coordinator/incident-reports/{$id}")->assertForbidden();
        $this->actAs($this->hr);
        $this->getJson("/api/v1/incident-reports/{$id}")->assertForbidden();
        $this->assertSame(0, $this->getJson('/api/v1/incident-reports?status=all')->json('meta.total'));

        $this->actAs($author);
        $this->postJson("/api/v1/coordinator/incident-reports/{$id}", ['title' => 'Loading stopped'])->assertOk();
        $this->postJson("/api/v1/coordinator/incident-reports/{$id}/submit")->assertOk();

        $this->postJson("/api/v1/coordinator/incident-reports/{$id}", ['title' => 'Rewritten'])->assertStatus(409);
        $this->deleteJson("/api/v1/coordinator/incident-reports/{$id}")->assertForbidden();
        $this->assertSame('Loading stopped', IncidentReport::find($id)->title);
    }

    public function test_the_office_answers_with_case_pending_and_case_resolved_and_the_thread_is_kept(): void
    {
        Notification::fake();
        $coordinator = $this->coordinatorFor($this->clientA);
        $this->actAs($coordinator);
        $id = $this->postJson('/api/v1/coordinator/incident-reports', $this->incident(['submit' => true]))->json('data.id');

        $this->actAs($this->hr);
        $this->assertSame([$id], array_column($this->getJson('/api/v1/incident-reports')->json('data'), 'id'));

        // Acknowledging a new report needs no note.
        $this->postJson("/api/v1/incident-reports/{$id}/notes", ['status' => 'under_review'])
            ->assertOk()->assertJsonPath('data.status_label', 'Case pending');

        // Resolving one does.
        $this->postJson("/api/v1/incident-reports/{$id}/notes", ['status' => 'resolved'])
            ->assertStatus(422)->assertJsonValidationErrors('note');

        $this->postJson("/api/v1/incident-reports/{$id}/notes", [
            'status' => 'resolved',
            'note' => 'Truck repaired; workers back on the 2 PM shift.',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');

        $report = IncidentReport::with('notes')->find($id);
        $this->assertSame($this->hr->id, $report->resolved_by);
        $this->assertCount(2, $report->notes);
        $this->assertSame(['under_review', 'resolved'], $report->notes->pluck('status_to')->all());
        Notification::assertSentTo($coordinator, IncidentReportUpdated::class);

        // The coordinator follows up; only the office moves the status.
        $this->actAs($coordinator);
        $this->postJson("/api/v1/coordinator/incident-reports/{$id}/notes", [
            'note' => 'Client confirms all 12 workers returned.',
            'status' => 'under_review',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertSame(3, IncidentReport::find($id)->notes()->count());
        Notification::assertSentTo($this->hr, IncidentReportUpdated::class);

        // Reopening a resolved case needs a reason and clears the resolution.
        $this->actAs($this->hr);
        $this->postJson("/api/v1/incident-reports/{$id}/notes", ['status' => 'under_review', 'note' => 'Truck failed again.'])
            ->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->assertNull(IncidentReport::find($id)->resolved_at);

        // Nothing in the thread was overwritten.
        $this->assertSame(4, IncidentReport::find($id)->notes()->count());
        $this->assertSame(0, EmployeeViolation::count());
    }

    public function test_evidence_is_optional_stored_privately_and_checked_by_content(): void
    {
        Storage::fake('documents');
        $coordinator = $this->coordinatorFor($this->clientA);
        $this->actAs($coordinator);

        $this->post('/api/v1/coordinator/incident-reports', $this->incident([
            'evidence' => $this->scriptUpload('photo.pdf'),
        ]), ['Accept' => 'application/json'])->assertStatus(422);

        $id = $this->post('/api/v1/coordinator/incident-reports', $this->incident([
            'evidence' => $this->pdfUpload('truck.pdf'),
            'submit' => '1',
        ]), ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $report = IncidentReport::find($id);
        $this->assertMatchesRegularExpression('#^incident-reports/\d+/[0-9a-f-]{36}\.pdf$#', $report->evidence_path);
        Storage::disk('documents')->assertExists($report->evidence_path);

        $response = $this->getJson("/api/v1/coordinator/incident-reports/{$id}");
        $this->assertTrue($response->json('data.has_evidence'));
        $this->assertArrayNotHasKey('evidence_path', $response->json('data'));

        $this->getJson("/api/v1/coordinator/incident-reports/{$id}/evidence")->assertOk()->assertJsonStructure(['data' => ['url']]);
        $this->actAs($this->hr);
        $this->getJson("/api/v1/incident-reports/{$id}/evidence")->assertOk()->assertJsonStructure(['data' => ['url']]);
    }
}
