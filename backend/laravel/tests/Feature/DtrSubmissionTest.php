<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\DtrSubmission;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DtrSubmissionReviewed;
use App\Notifications\DtrSubmissionSubmitted;
use App\Services\CoordinatorAssignmentService;
use Database\Seeders\DtrSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concurrency\ThrowawayDatabase;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The coordinator hands the client's DTR file to HR; HR imports it with the
 * existing importer.
 *
 * Uses the synthetic workers of DtrSampleSeeder and the sample files in
 * docs/09-testing/dtr-samples/. "Today" is 4 November 2026; the files cover
 * Monday 19 to Sunday 25 October 2026.
 */
class DtrSubmissionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private const PERIOD = ['2026-10-19', '2026-10-25'];

    private User $hr;
    private User $coordinator;
    private ClientCompany $client;
    private ClientCompany $otherClient;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            ThrowawayDatabase::assert();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-04 09:00:00'));
        Storage::fake('documents');

        $this->seedReferenceData();
        $this->adminUser();
        $this->hr = $this->hrUser();
        $this->seed(DtrSampleSeeder::class);

        $this->client = ClientCompany::where('company_name', DtrSampleSeeder::CLIENT)->sole();
        $this->otherClient = ClientCompany::where('company_name', DtrSampleSeeder::OTHER_CLIENT)->sole();
        $this->coordinator = User::where('email', DtrSampleSeeder::COORDINATOR_EMAIL)->sole();
    }

    // ------------------------------------------------------------- helpers

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function sample(string $file): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'dtr');
        copy(base_path('../../docs/09-testing/dtr-samples/'.$file), $copy);

        return new UploadedFile($copy, $file, null, null, true);
    }

    private function submit(?UploadedFile $file = null, array $overrides = [], string $url = '/api/v1/coordinator/dtr-submissions')
    {
        return $this->post($url, $overrides + [
            'file' => $file ?? $this->sample('EMPOWER_DTR_Valid_Test_Data.csv'),
            'period_from' => self::PERIOD[0],
            'period_to' => self::PERIOD[1],
            'source_note' => 'Received from the client HR on 26 Oct 2026',
        ], ['Accept' => 'application/json']);
    }

    /** As the coordinator: send the valid sample. Returns the submission id. */
    private function submitted(string $file = 'EMPOWER_DTR_Valid_Test_Data.csv'): int
    {
        $this->actAs($this->coordinator);

        return $this->submit($this->sample($file))->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');
    }

    /** As HR: open the submission in the importer, accept the suggested columns, preview. */
    private function openInImporter(int $submissionId): array
    {
        $this->actAs($this->hr);
        $importId = $this->postJson("/api/v1/attendance/dtr-submissions/{$submissionId}/import")->assertOk()->json('data.import_id');

        // The existing importer's own screens, from here on.
        $draft = $this->getJson("/api/v1/attendance/imports/{$importId}")->assertOk();
        $preview = $this->postJson("/api/v1/attendance/imports/{$importId}/preview", $draft->json('data.mapping'))->assertOk();

        return [$importId, $draft->json('data'), $preview->json('data')];
    }

    private function coordinatorFor(ClientCompany $company): User
    {
        $user = User::create([
            'first_name' => 'Other', 'last_name' => 'Coordinator',
            'email' => 'coordinator.'.uniqid().'@example.test', 'password' => Hash::make('x'),
            'user_type' => 'coordinator', 'is_active' => true,
        ]);
        $user->assignRole('coordinator');
        app(CoordinatorAssignmentService::class)->assign($user, $company, User::role('admin')->first());

        return $user;
    }

    // ---------------------------------------------------------------- tests

    public function test_a_coordinator_sends_the_clients_dtr_file_and_nothing_is_imported(): void
    {
        Notification::fake();
        $this->actAs($this->coordinator);

        // Only the assigned client is offered, with the importer's own limits.
        $options = $this->getJson('/api/v1/coordinator/dtr-submissions/options')->assertOk()->json('data');
        $this->assertSame([$this->client->id], array_column($options['clients'], 'id'));
        $this->assertSame(['csv', 'txt', 'xlsx', 'xls'], $options['extensions']);
        $this->assertSame(62, $options['max_period_days']);

        // A client id in the request is ignored: the client is the assignment.
        $response = $this->submit(null, ['client_company_id' => $this->otherClient->id])->assertCreated();
        $submission = DtrSubmission::findOrFail($response->json('data.id'));

        $this->assertSame($this->client->id, $submission->client_company_id);
        $this->assertSame('submitted', $submission->status);
        $this->assertSame(['2026-10-19', '2026-10-25'], [$submission->period_from->toDateString(), $submission->period_to->toDateString()]);
        $this->assertSame('EMPOWER_DTR_Valid_Test_Data.csv', $submission->original_filename);
        $this->assertSame(15, $submission->total_rows);
        $this->assertSame($this->coordinator->id, $submission->submitted_by);
        $this->assertMatchesRegularExpression('/^DTRS-2026-\d+$/', $submission->submission_code);

        // Kept as received, on the private disk, under a random key.
        Storage::disk('documents')->assertExists($submission->file_path);
        $this->assertStringStartsWith("dtr-submissions/{$this->client->id}/", $submission->file_path);
        $this->assertSame(hash_file('sha256', base_path('../../docs/09-testing/dtr-samples/EMPOWER_DTR_Valid_Test_Data.csv')), $submission->file_hash);
        $this->assertArrayNotHasKey('file_path', $response->json('data'));

        // No attendance and no import draft from a submission.
        $this->assertSame(0, AttendanceRecord::count());
        $this->assertSame(0, AttendanceImport::count());

        Notification::assertSentTo($this->hr, DtrSubmissionSubmitted::class,
            fn ($n) => $n->toArray($this->hr)['link'] === "/attendance?tab=submissions&submission={$submission->id}");
        Notification::assertNotSentTo($this->coordinator, DtrSubmissionSubmitted::class);
        $this->assertTrue(AuditLog::where('record_type', 'DtrSubmission')->where('record_id', $submission->id)->where('action_type', 'submit')->exists());

        // The coordinator sees it, and can fetch the file back as sent.
        $this->assertSame([$submission->id], array_column($this->getJson('/api/v1/coordinator/dtr-submissions')->json('data'), 'id'));
        $this->get("/api/v1/coordinator/dtr-submissions/{$submission->id}/file")->assertOk();
    }

    public function test_files_the_importer_would_refuse_are_refused_at_submission(): void
    {
        $this->actAs($this->coordinator);

        $this->submit(UploadedFile::fake()->createWithContent('dtr.pdf', "%PDF-1.4\n%%EOF"))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->submit(UploadedFile::fake()->create('dtr.csv', 6000))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        // A workbook renamed .csv: the importer's own content check.
        $this->submit(UploadedFile::fake()->createWithContent('dtr.csv', "PK\x03\x04binary"))->assertUnprocessable();
        $this->submit(null, ['period_from' => '2026-08-01', 'period_to' => '2026-10-25'])
            ->assertUnprocessable()->assertJsonValidationErrors('period_to');
        $this->submit(null, ['period_to' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors('period_to');
        $this->submit(null, ['file' => null])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, DtrSubmission::count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_hr_imports_the_submission_with_the_existing_importer(): void
    {
        Notification::fake();
        $id = $this->submitted();

        // The inbox, with its filters.
        $this->actAs($this->hr);
        $inbox = $this->getJson('/api/v1/attendance/dtr-submissions')->assertOk();
        $this->assertSame([$id], array_column($inbox->json('data'), 'id'));
        $this->assertSame(1, $inbox->json('meta.status_counts.submitted'));
        $this->assertSame($this->coordinator->id, $inbox->json('meta.coordinators.0.id'));
        $this->assertSame(1, $this->getJson("/api/v1/attendance/dtr-submissions?client_company_id={$this->client->id}&coordinator_id={$this->coordinator->id}&covers_from=2026-10-20&covers_to=2026-10-21")->json('meta.total'));
        $this->assertSame(0, $this->getJson("/api/v1/attendance/dtr-submissions?client_company_id={$this->otherClient->id}")->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/v1/attendance/dtr-submissions?covers_from=2026-10-26')->json('meta.total'));

        // The original file, exactly as sent.
        $file = $this->get("/api/v1/attendance/dtr-submissions/{$id}/file")->assertOk();
        $this->assertSame(file_get_contents(base_path('../../docs/09-testing/dtr-samples/EMPOWER_DTR_Valid_Test_Data.csv')), $file->getContent());

        [$importId, $draft, $preview] = $this->openInImporter($id);

        // An ordinary draft of the existing importer, prefilled from the submission.
        $this->assertSame('draft', $draft['status']);
        $this->assertSame($this->client->id, $draft['client_company_id']);
        $this->assertSame(['2026-10-19', '2026-10-25'], [$draft['period_from'], $draft['period_to']]);
        $this->assertSame('EMPOWER_DTR_Valid_Test_Data.csv', $draft['original_filename']);
        $this->assertSame($id, AttendanceImport::find($importId)->dtr_submission_id);
        $this->assertSame(15, $preview['ready']);
        $this->assertSame('under_review', DtrSubmission::find($id)->status);

        // Opening it again continues the same draft.
        $this->assertSame($importId, $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/import")->json('data.import_id'));

        $this->postJson("/api/v1/attendance/imports/{$importId}/confirm", ['conflict_action' => 'skip'])
            ->assertOk()->assertJsonPath('data.imported_rows', 15)
            ->assertJsonPath('data.dtr_submission.id', $id);

        $submission = DtrSubmission::find($id);
        $this->assertSame('imported', $submission->status);
        $this->assertSame($importId, $submission->attendance_import_id);
        $this->assertNotNull($submission->imported_at);
        $this->assertSame(15, AttendanceRecord::where('attendance_import_id', $importId)->count());
        Notification::assertSentTo($this->coordinator, DtrSubmissionReviewed::class, fn ($n) => str_contains($n->toArray($this->coordinator)['title'], 'imported'));

        // The existing comparison now reads the imported DTR: Alma, 21 Oct, 17 min late.
        $alma = Employee::where('biometric_number', '90001')->sole();
        $day = collect($this->getJson("/api/v1/employees/{$alma->id}/attendance?from=2026-10-21&to=2026-10-21")->json('data.days'))->first();
        $this->assertSame(['late'], $day['discrepancies']);
        $this->assertSame(17, $day['late_minutes']);

        // Imported is final, and the coordinator sees the import.
        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/import")->assertStatus(409);
        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/decision", ['action' => 'return', 'note' => 'Too late now'])->assertStatus(409);
        $this->actAs($this->coordinator);
        $this->getJson("/api/v1/coordinator/dtr-submissions/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'imported')
            ->assertJsonPath('data.import.imported_rows', 15);
    }

    public function test_a_failed_import_never_marks_the_submission_imported(): void
    {
        $id = $this->submitted('EMPOWER_DTR_Invalid_Test_Data.csv');
        [$importId, , $preview] = $this->openInImporter($id);

        $this->assertSame(0, $preview['ready']);
        $this->postJson("/api/v1/attendance/imports/{$importId}/confirm", ['conflict_action' => 'skip'])->assertStatus(422);

        $this->assertSame('under_review', DtrSubmission::find($id)->status);
        $this->assertNull(DtrSubmission::find($id)->attendance_import_id);
        $this->assertSame(0, AttendanceRecord::count());

        // Abandoning the draft puts it back in the inbox.
        $this->postJson("/api/v1/attendance/imports/{$importId}/discard")->assertOk();
        $this->assertSame('submitted', DtrSubmission::find($id)->status);
        // The original stays; only the importer's working copy went.
        Storage::disk('documents')->assertExists(DtrSubmission::find($id)->file_path);
    }

    public function test_hr_returns_a_file_and_the_coordinator_sends_a_corrected_one(): void
    {
        Notification::fake();
        $id = $this->submitted('EMPOWER_DTR_Invalid_Test_Data.csv');
        [$importId] = $this->openInImporter($id);

        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/decision", ['action' => 'return'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/decision", [
            'action' => 'return', 'note' => 'Dates are day-first and several times are unreadable. Ask the client for a clean export.',
        ])->assertOk()->assertJsonPath('data.status', 'returned');

        $returned = DtrSubmission::find($id);
        $this->assertSame('discarded', AttendanceImport::find($importId)->status, 'The open draft is abandoned.');
        $this->assertSame($this->hr->id, $returned->reviewed_by);
        Notification::assertSentTo($this->coordinator, DtrSubmissionReviewed::class,
            fn ($n) => str_contains($n->toArray($this->coordinator)['body'], 'clean export'));

        // The coordinator sees why, and sends a corrected file.
        $this->actAs($this->coordinator);
        $this->getJson("/api/v1/coordinator/dtr-submissions/{$id}")->assertOk()
            ->assertJsonPath('data.review_note', 'Dates are day-first and several times are unreadable. Ask the client for a clean export.');
        $corrected = $this->submit(null, [], "/api/v1/coordinator/dtr-submissions/{$id}/resubmit")->assertCreated();

        $this->assertSame($id, $corrected->json('data.resubmission_of.id'));
        $this->assertSame('submitted', $corrected->json('data.status'));

        // The returned one is untouched: same file, same status, same reason.
        $this->assertSame('returned', $returned->fresh()->status);
        Storage::disk('documents')->assertExists($returned->file_path);
        $this->assertNotSame($returned->file_path, DtrSubmission::find($corrected->json('data.id'))->file_path);
        $this->assertSame($corrected->json('data.id'), $this->getJson("/api/v1/coordinator/dtr-submissions/{$id}")->json('data.resubmission.id'));

        // Corrected once only, and only what HR returned.
        $this->submit($this->sample('EMPOWER_DTR_Duplicate_Test_Data.csv'), [], "/api/v1/coordinator/dtr-submissions/{$id}/resubmit")->assertStatus(409);
        $this->submit($this->sample('EMPOWER_DTR_Duplicate_Test_Data.csv'), [], "/api/v1/coordinator/dtr-submissions/{$corrected->json('data.id')}/resubmit")->assertStatus(409);

        $events = DtrSubmission::find($id)->events->map(fn ($e) => [$e->from_status, $e->to_status])->all();
        $this->assertSame([[null, 'submitted'], ['submitted', 'under_review'], ['under_review', 'submitted'], ['submitted', 'returned']], $events);
    }

    public function test_hr_can_reject_a_file_with_a_reason(): void
    {
        Notification::fake();
        $id = $this->submitted();
        $this->actAs($this->hr);

        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/decision", ['action' => 'reject', 'note' => 'Not this client\'s DTR.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/import")->assertStatus(409);
        Notification::assertSentTo($this->coordinator, DtrSubmissionReviewed::class);

        // A rejected file can be sent again (it may have been rejected by mistake) - as a new submission.
        $this->actAs($this->coordinator);
        $this->submit()->assertCreated();
        // Only a returned file is "corrected"; a rejected one is not.
        $this->submit($this->sample('EMPOWER_DTR_Duplicate_Test_Data.csv'), [], "/api/v1/coordinator/dtr-submissions/{$id}/resubmit")->assertStatus(409);
    }

    public function test_access_is_limited_to_the_assigned_client_and_to_hr(): void
    {
        $id = $this->submitted();
        $other = $this->coordinatorFor($this->otherClient);

        $this->actAs($other);
        $this->assertSame([], $this->getJson('/api/v1/coordinator/dtr-submissions')->json('data'));
        $this->getJson("/api/v1/coordinator/dtr-submissions/{$id}")->assertForbidden();
        $this->get("/api/v1/coordinator/dtr-submissions/{$id}/file")->assertForbidden();
        $this->submit(null, [], "/api/v1/coordinator/dtr-submissions/{$id}/resubmit")->assertForbidden();

        // Coordinators never reach HR's inbox or the importer.
        $this->actAs($this->coordinator);
        $this->getJson('/api/v1/attendance/dtr-submissions')->assertForbidden();
        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/import")->assertForbidden();
        $this->get("/api/v1/attendance/dtr-submissions/{$id}/file")->assertForbidden();

        // Read-only attendance access does not reach the inbox either.
        $viewer = User::factory()->create(['email' => 'viewer.'.uniqid().'@example.test', 'user_type' => 'hr']);
        $viewer->givePermissionTo('attendance.view');
        $this->actAs($viewer);
        $this->getJson('/api/v1/attendance/dtr-submissions')->assertForbidden();
        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/import")->assertForbidden();
        $this->postJson("/api/v1/attendance/dtr-submissions/{$id}/decision", ['action' => 'reject', 'note' => 'Not allowed'])->assertForbidden();

        $this->assertSame('submitted', DtrSubmission::find($id)->status);
        $this->assertSame(0, AttendanceImport::count());
    }

    public function test_the_same_file_is_never_sent_or_imported_twice(): void
    {
        $id = $this->submitted();

        // The same file again, while HR has the first.
        $this->submit()->assertStatus(409);

        // Imported through the submission...
        [$importId] = $this->openInImporter($id);
        $this->postJson("/api/v1/attendance/imports/{$importId}/confirm", ['conflict_action' => 'skip'])->assertOk();

        // ...it cannot be sent again, nor uploaded again by HR directly.
        $this->actAs($this->coordinator);
        $this->submit()->assertStatus(409);
        $this->actAs($this->hr);
        $this->post('/api/v1/attendance/imports', [
            'file' => $this->sample('EMPOWER_DTR_Valid_Test_Data.csv'),
            'client_company_id' => $this->client->id,
            'period_from' => self::PERIOD[0],
            'period_to' => self::PERIOD[1],
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertSame(15, AttendanceRecord::count());
    }

    public function test_a_file_hr_already_imported_directly_is_refused_and_direct_upload_still_works(): void
    {
        $this->actAs($this->hr);
        $upload = $this->post('/api/v1/attendance/imports', [
            'file' => $this->sample('EMPOWER_DTR_Valid_Test_Data.csv'),
            'client_company_id' => $this->client->id,
            'period_from' => self::PERIOD[0],
            'period_to' => self::PERIOD[1],
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/v1/attendance/imports/{$upload->json('data.id')}/preview", $upload->json('data.mapping'))->assertOk();
        $this->postJson("/api/v1/attendance/imports/{$upload->json('data.id')}/confirm", ['conflict_action' => 'skip'])
            ->assertOk()->assertJsonPath('data.dtr_submission', null);

        $this->actAs($this->coordinator);
        $this->submit()->assertStatus(409);
        $this->assertSame(0, DtrSubmission::count());
    }
}
