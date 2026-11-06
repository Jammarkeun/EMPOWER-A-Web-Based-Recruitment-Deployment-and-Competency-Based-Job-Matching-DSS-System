<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\ClientCompanyDocument;
use App\Models\User;
use App\Services\CoordinatorAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * A client company's own documents: titled by the uploader, kept on private
 * storage, reachable only through the company they belong to.
 *
 * There is no fixed list of document types and no document every company must
 * have, so nothing here checks for one.
 */
class ClientCompanyDocumentsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferenceData();
        $this->hr = $this->hrUser();
        Sanctum::actingAs($this->hr);
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function pdf(string $name = 'bir-2303.pdf', string $marker = ''): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%{$marker}\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($path, $name, null, null, true);
    }

    private function realFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function upload(ClientCompany $company, string $title, ?UploadedFile $file = null, array $extra = [])
    {
        return $this->post("/api/v1/clients/{$company->id}/documents", array_merge([
            'title' => $title,
            'file' => $file ?? $this->pdf(),
        ], $extra), ['Accept' => 'application/json']);
    }

    private function storedFiles(): array
    {
        return Storage::disk('documents')->allFiles();
    }

    public function test_a_new_company_can_be_given_several_documents_with_titles_of_its_own(): void
    {
        $id = $this->postJson('/api/v1/clients', [
            'company_name' => 'Best TV Food Products Corporation',
            'business_type' => 'Food Manufacturing',
            'office_address' => 'Sta. Cruz, Laguna',
        ])->assertCreated()->json('data.id');
        $company = ClientCompany::find($id);

        $this->upload($company, 'BIR Registration Certificate', $this->pdf('2303.pdf', 'a'), [
            'description' => 'Form 2303',
            'issued_on' => '2024-01-15',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'BIR Registration Certificate')
            ->assertJsonPath('data.file_name', '2303.pdf')
            ->assertJsonPath('data.file_type', 'PDF')
            ->assertJsonPath('data.description', 'Form 2303')
            ->assertJsonPath('data.issued_on', '2024-01-15')
            ->assertJsonPath('data.expires_on', null)
            ->assertJsonPath('data.is_expired', false)
            ->assertJsonPath('data.uploaded_by', $this->hr->full_name)
            ->assertJsonMissingPath('data.file_path');

        $this->upload($company, "Mayor's Permit 2026", UploadedFile::fake()->image('permit.png', 800, 600))
            ->assertCreated()
            ->assertJsonPath('data.file_type', 'Image');

        $this->getJson("/api/v1/clients/{$id}/documents")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'BIR Registration Certificate')
            ->assertJsonPath('data.1.title', "Mayor's Permit 2026");

        $this->assertSame(2, ClientCompanyDocument::where('client_company_id', $id)->count());
        $this->assertCount(2, $this->storedFiles());
        // Stored under a random name in the company's folder, never the uploader's name.
        $stored = ClientCompanyDocument::where('client_company_id', $id)->pluck('file_path');
        foreach ($stored as $path) {
            $this->assertMatchesRegularExpression("#^client-documents/{$id}/[0-9a-f-]{36}\.(pdf|png)$#", $path);
        }

        $this->assertTrue(AuditLog::where('record_type', 'ClientCompanyDocument')
            ->where('action_type', 'create')->where('client_company_id', $id)->exists());
    }

    public function test_each_company_keeps_its_own_documents_and_count(): void
    {
        $tiwi = $this->clientCompany($this->hr);
        $other = $this->clientCompany($this->hr, ['company_name' => 'Laguna Plastics Inc.']);
        $empty = $this->clientCompany($this->hr, ['company_name' => 'Calamba Steel Works']);

        $this->upload($tiwi, 'BIR Registration Certificate', $this->pdf('a.pdf', 'a'))->assertCreated();
        $this->upload($tiwi, 'Business Permit', $this->pdf('b.pdf', 'b'))->assertCreated();
        $this->upload($tiwi, 'FDA License to Operate', $this->pdf('c.pdf', 'c'))->assertCreated();
        $this->upload($other, 'PEZA Accreditation', $this->pdf('d.pdf', 'd'))->assertCreated();

        $this->getJson("/api/v1/clients/{$other->id}/documents")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'PEZA Accreditation');

        $this->getJson("/api/v1/clients/{$tiwi->id}/documents?search=permit")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Business Permit');

        $counts = collect($this->getJson('/api/v1/clients?per_page=100')->assertOk()->json('data'))
            ->pluck('documents_count', 'id');
        $this->assertSame(3, $counts[$tiwi->id]);
        $this->assertSame(1, $counts[$other->id]);
        // No documents is simply none, not a fault.
        $this->assertSame(0, $counts[$empty->id]);
    }

    public function test_the_document_opens_and_downloads_as_the_file_that_was_uploaded(): void
    {
        $company = $this->clientCompany($this->hr);
        $file = $this->pdf('BIR Form 2303.pdf', 'unique-bytes');
        $bytes = file_get_contents($file->getRealPath());
        $id = $this->upload($company, 'BIR Registration Certificate', $file)->assertCreated()->json('data.id');

        $url = $this->getJson("/api/v1/clients/{$company->id}/documents/{$id}/link")->assertOk()->json('data.url');
        $this->assertStringContainsString('signature=', $url);
        $opened = $this->get($url)->assertOk();
        $this->assertSame($bytes, $opened->streamedContent());

        $download = $this->get("/api/v1/clients/{$company->id}/documents/{$id}/download", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($bytes, $download->getContent());
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('BIR Form 2303.pdf', $download->headers->get('Content-Disposition'));
    }

    public function test_editing_the_title_and_details_keeps_the_same_file(): void
    {
        $company = $this->clientCompany($this->hr);
        $id = $this->upload($company, 'BIR Cert')->assertCreated()->json('data.id');
        $before = ClientCompanyDocument::find($id);

        $this->patchJson("/api/v1/clients/{$company->id}/documents/{$id}", [
            'title' => 'BIR Certificate of Registration (Form 2303)',
            'expires_on' => '2030-12-31',
        ])->assertOk()
            ->assertJsonPath('data.title', 'BIR Certificate of Registration (Form 2303)')
            ->assertJsonPath('data.expires_on', '2030-12-31')
            ->assertJsonPath('data.file_name', 'bir-2303.pdf');

        $after = ClientCompanyDocument::find($id);
        $this->assertSame($before->file_path, $after->file_path);
        $this->assertSame($before->file_hash, $after->file_hash);
        $this->assertSame($this->hr->id, $after->updated_by);

        $this->patchJson("/api/v1/clients/{$company->id}/documents/{$id}", ['title' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_replacing_one_documents_file_leaves_every_other_document_alone(): void
    {
        $company = $this->clientCompany($this->hr);
        $keep = $this->upload($company, 'Business Permit', $this->pdf('permit.pdf', 'permit'))->json('data.id');
        $replace = $this->upload($company, 'BIR Registration', $this->pdf('old.pdf', 'old'))->json('data.id');
        $keptBefore = ClientCompanyDocument::find($keep)->only(['file_path', 'file_hash', 'file_name', 'title']);
        $oldPath = ClientCompanyDocument::find($replace)->file_path;

        $this->post("/api/v1/clients/{$company->id}/documents/{$replace}/file", [
            'file' => $this->pdf('new.pdf', 'new'),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.title', 'BIR Registration')
            ->assertJsonPath('data.file_name', 'new.pdf');

        $replaced = ClientCompanyDocument::find($replace);
        $this->assertNotSame($oldPath, $replaced->file_path);
        $this->assertSame($keptBefore, ClientCompanyDocument::find($keep)->only(['file_path', 'file_hash', 'file_name', 'title']));

        // The superseded file is gone; the new and the untouched one remain.
        Storage::disk('documents')->assertMissing($oldPath);
        Storage::disk('documents')->assertExists($replaced->file_path);
        Storage::disk('documents')->assertExists($keptBefore['file_path']);

        $audit = AuditLog::where('action_type', 'replace_file')->where('record_id', $replace)->firstOrFail();
        $this->assertSame('old.pdf', $audit->old_values_json['file_name']);
        $this->assertSame('new.pdf', $audit->new_values_json['file_name']);
        $this->assertArrayNotHasKey('file_path', $audit->old_values_json);
    }

    public function test_unsupported_disguised_and_oversized_files_are_refused_and_nothing_is_kept(): void
    {
        $company = $this->clientCompany($this->hr);

        $this->upload($company, 'Web page', UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        // Named like a PDF, judged by content. Real files rather than fakes:
        // a fake reports its type from its name, which is what is under test.
        $this->upload($company, 'Fake PDF', $this->realFile('permit.pdf', '<?php echo "hi"; ?>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($company, 'Notes as PDF', $this->realFile('permit.pdf', "just some notes\n"))
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->upload($company, 'Program', UploadedFile::fake()->create('setup.exe', 10))
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->upload($company, 'Too big', UploadedFile::fake()->create('scan.pdf', 11 * 1024, 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->upload($company, '', $this->pdf())
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->upload($company, str_repeat('T', 151), $this->pdf())
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->post("/api/v1/clients/{$company->id}/documents", ['title' => 'No file'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, ClientCompanyDocument::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_dates_are_optional_and_an_expiry_before_the_issue_date_is_refused(): void
    {
        $company = $this->clientCompany($this->hr);

        $this->upload($company, 'Permit', $this->pdf('a.pdf', 'a'), ['issued_on' => '2026-01-10', 'expires_on' => '2025-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_on');

        $this->upload($company, 'Permit', $this->pdf('a.pdf', 'a'), ['issued_on' => now()->addDays(5)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('issued_on');

        // A document past its own expiry date says so; one with no date never does.
        $this->upload($company, 'Old permit', $this->pdf('b.pdf', 'b'), ['expires_on' => '2025-12-31'])
            ->assertCreated()->assertJsonPath('data.is_expired', true);
        $this->upload($company, 'SEC Registration', $this->pdf('c.pdf', 'c'))
            ->assertCreated()->assertJsonPath('data.expires_on', null)->assertJsonPath('data.is_expired', false);
    }

    public function test_another_companys_document_cannot_be_reached_through_a_different_company(): void
    {
        $mine = $this->clientCompany($this->hr);
        $theirs = $this->clientCompany($this->hr, ['company_name' => 'Laguna Plastics Inc.']);
        $id = $this->upload($theirs, 'Confidential Permit')->json('data.id');

        $base = "/api/v1/clients/{$mine->id}/documents/{$id}";
        $this->getJson("{$base}/link")->assertNotFound();
        $this->getJson("{$base}/download")->assertNotFound();
        $this->patchJson($base, ['title' => 'Hijacked'])->assertNotFound();
        $this->post("{$base}/file", ['file' => $this->pdf('x.pdf', 'x')], ['Accept' => 'application/json'])->assertNotFound();
        $this->actAs($this->adminUser());
        $this->deleteJson($base)->assertNotFound();

        $this->getJson('/api/v1/clients/999999/documents')->assertNotFound();
        $this->getJson("/api/v1/clients/{$theirs->id}/documents/999999/link")->assertNotFound();

        $this->assertSame('Confidential Permit', ClientCompanyDocument::find($id)->title);
        $this->assertSame(1, ClientCompanyDocument::count());
    }

    public function test_coordinators_cannot_read_or_manage_company_documents(): void
    {
        $company = $this->clientCompany($this->hr);
        $id = $this->upload($company, 'BIR Registration')->json('data.id');

        $coordinator = User::factory()->create(['email' => 'coord.'.uniqid().'@cdemanpower.local', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        // Even the company they are assigned to.
        app(CoordinatorAssignmentService::class)->assign($coordinator, $company, $this->adminUser());
        $this->actAs($coordinator);

        $this->getJson("/api/v1/clients/{$company->id}/documents")->assertForbidden();
        $this->getJson("/api/v1/clients/{$company->id}/documents/{$id}/link")->assertForbidden();
        $this->getJson("/api/v1/clients/{$company->id}/documents/{$id}/download")->assertForbidden();
        $this->upload($company, 'Sneaky')->assertForbidden();

        $this->assertSame(1, ClientCompanyDocument::count());
    }

    public function test_only_an_administrator_removes_a_document_and_the_record_and_file_are_kept(): void
    {
        $company = $this->clientCompany($this->hr);
        $id = $this->upload($company, 'Business Permit')->json('data.id');
        $path = ClientCompanyDocument::find($id)->file_path;

        $this->deleteJson("/api/v1/clients/{$company->id}/documents/{$id}")->assertForbidden();

        $admin = $this->adminUser();
        $this->actAs($admin);
        $this->deleteJson("/api/v1/clients/{$company->id}/documents/{$id}")->assertOk();

        $this->getJson("/api/v1/clients/{$company->id}/documents")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/clients/{$company->id}/documents/{$id}/link")->assertNotFound();

        $removed = ClientCompanyDocument::withTrashed()->find($id);
        $this->assertTrue($removed->trashed());
        $this->assertSame($admin->id, $removed->deleted_by);
        Storage::disk('documents')->assertExists($path);
        $this->assertTrue(AuditLog::where('action_type', 'delete')->where('record_type', 'ClientCompanyDocument')->where('record_id', $id)->exists());
    }

    public function test_sending_the_same_file_and_title_again_does_not_make_a_duplicate(): void
    {
        $company = $this->clientCompany($this->hr);

        $first = $this->upload($company, 'BIR Registration', $this->pdf('a.pdf', 'same'))->assertCreated()->json('data.id');
        $again = $this->upload($company, 'BIR Registration', $this->pdf('a.pdf', 'same'))->assertOk()->json('data.id');

        $this->assertSame($first, $again);
        $this->assertSame(1, ClientCompanyDocument::count());
        $this->assertCount(1, $this->storedFiles());

        // The same file under a different title is a different document.
        $this->upload($company, 'BIR Registration (copy for SSS)', $this->pdf('a.pdf', 'same'))->assertCreated();
        $this->assertSame(2, ClientCompanyDocument::count());
    }

    public function test_a_record_that_fails_to_save_takes_its_stored_file_with_it(): void
    {
        $company = $this->clientCompany($this->hr);

        ClientCompanyDocument::creating(function () {
            throw new RuntimeException('Simulated database failure');
        });

        $this->upload($company, 'BIR Registration')->assertStatus(500);

        $this->assertSame(0, ClientCompanyDocument::withTrashed()->count());
        $this->assertSame([], $this->storedFiles());
        // The company itself is untouched by a failed upload.
        $this->assertSame(1, ClientCompany::count());
    }

    public function test_a_replacement_that_fails_to_save_leaves_the_document_as_it_was(): void
    {
        $company = $this->clientCompany($this->hr);
        $id = $this->upload($company, 'BIR Registration', $this->pdf('old.pdf', 'old'))->json('data.id');
        $before = ClientCompanyDocument::find($id)->only(['file_path', 'file_hash', 'file_name']);

        ClientCompanyDocument::updating(function () {
            throw new RuntimeException('Simulated database failure');
        });

        $this->post("/api/v1/clients/{$company->id}/documents/{$id}/file", ['file' => $this->pdf('new.pdf', 'new')], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertSame($before, ClientCompanyDocument::find($id)->only(['file_path', 'file_hash', 'file_name']));
        $this->assertSame([$before['file_path']], $this->storedFiles());
    }

    public function test_a_company_without_documents_stays_viewable_and_editable(): void
    {
        $company = $this->clientCompany($this->hr);

        $this->getJson("/api/v1/clients/{$company->id}")->assertOk();
        $this->getJson("/api/v1/clients/{$company->id}/overview")->assertOk();
        $this->getJson("/api/v1/clients/{$company->id}/documents")->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/clients/{$company->id}", ['contact_person' => 'Ana Reyes'])->assertOk()
            ->assertJsonPath('data.contact_person', 'Ana Reyes');
        $this->postJson("/api/v1/clients/{$company->id}/departments", ['department_name' => 'Packaging'])->assertCreated();
    }
}
