<?php

namespace Tests\Feature;

use App\Models\ApplicantRequirement;
use App\Models\RequirementType;
use App\Models\User;
use App\Services\DocumentStorageService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Audit R-01 and R-02: documents are private, links are signed and short-lived,
 * and a stored file's type comes from its content, never from its name.
 *
 * Applicant documents include birth certificates and medical results. On local
 * machines they used to sit on the `public` disk, where anybody holding a link
 * could open them for ever, and a PDF uploaded as "notes.html" was stored - and
 * could be served - as a web page. Every test here would have failed against
 * that implementation.
 */
class DocumentStorageSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private string $signedRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    protected function tearDown(): void
    {
        if (isset($this->signedRoot)) {
            (new Filesystem)->deleteDirectory($this->signedRoot);
        }

        parent::tearDown();
    }

    /**
     * The real `documents` disk - not a fake - pointed at a scratch folder, so
     * the links are signed exactly as they are when the system runs.
     */
    private function useRealDocumentsDisk(): void
    {
        $this->signedRoot = storage_path('framework/testing/disks/documents-signed');
        config([
            'empower.uploads.disk' => 'documents',
            'filesystems.disks.documents.root' => $this->signedRoot,
        ]);
        Storage::forgetDisk('documents');
    }

    private function relative(string $url): string
    {
        $parts = parse_url($url);

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function portalUserFor($applicant): User
    {
        $user = User::factory()->create([
            'email' => 'portal'.$applicant->id.'.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $user->assignRole('portal');

        return $user;
    }

    /** A requirement the portal accepts without an expiry date. */
    private function plainRequirementType(): RequirementType
    {
        return RequirementType::active()->where('has_expiry', false)->firstOrFail();
    }

    /** A file on disk whose type the server must sniff from its bytes. */
    private function realUpload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    // ------------------------------------------------------------------ R-01

    public function test_a_document_link_is_signed_works_once_signed_and_expires(): void
    {
        $this->useRealDocumentsDisk();
        Storage::disk('documents')->put('requirements/7/medical.pdf', $this->pdfBytes());

        $url = app(DocumentStorageService::class)->temporaryUrl('requirements/7/medical.pdf', 5);

        $this->assertStringContainsString('/api/v1/document-files/requirements/7/medical.pdf', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        // The signed link opens the file as exactly what it is, inline (so a
        // PDF displays in the browser), and tells caches not to keep it.
        $response = $this->get($this->relative($url))->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        // No CSP sandbox on a PDF: Chrome refuses to display PDFs under one.
        $this->assertStringNotContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        // The same address without its signature is refused.
        $this->get('/api/v1/document-files/requirements/7/medical.pdf')->assertForbidden();

        // A signature cannot be moved to another file.
        Storage::disk('documents')->put('requirements/8/other.pdf', $this->pdfBytes());
        $tampered = str_replace('requirements/7/medical.pdf', 'requirements/8/other.pdf', $this->relative($url));
        $this->get($tampered)->assertForbidden();

        // Once the minutes have passed the link stops working.
        $this->travel(6)->minutes();
        $this->get($this->relative($url))->assertForbidden();
    }

    public function test_even_a_validly_signed_link_serves_only_stored_document_types(): void
    {
        $this->useRealDocumentsDisk();
        Storage::disk('documents')->put('requirements/7/page.html', '<script>alert(1)</script>');
        Storage::disk('documents')->put('requirements/7/photo.png', 'not-really-a-png');

        $sign = fn (string $path) => $this->relative(URL::temporarySignedRoute(
            'documents.file', now()->addMinutes(5), ['path' => $path], absolute: false
        ));

        // A type the system never stores is not served, signature or not.
        $this->get($sign('requirements/7/page.html'))->assertNotFound();
        // Nor can a signed path climb out of the document folder.
        $this->get($sign('../../../.env'))->assertNotFound();
        $this->get($sign('requirements/7/missing.pdf'))->assertNotFound();

        // An image is served with its own type and a sandboxing policy.
        $image = $this->get($sign('requirements/7/photo.png'))->assertOk();
        $image->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('sandbox', $image->headers->get('Content-Security-Policy'));
    }

    public function test_documents_are_not_reachable_through_the_public_storage_path(): void
    {
        $this->useRealDocumentsDisk();
        Storage::disk('documents')->put('requirements/7/medical.pdf', $this->pdfBytes());

        // Stored outside the web root: neither the old /storage address nor a
        // guess at the folder layout reaches the file.
        $this->assertFileDoesNotExist(public_path('storage/requirements/7/medical.pdf'));
        // (/storage/* is answered by the signed-only private disk route, so the
        // refusal is a 403 rather than a 404 - either way, no file.)
        $status = $this->get('/storage/requirements/7/medical.pdf')->status();
        $this->assertContains($status, [403, 404]);
    }

    public function test_the_public_disk_is_refused_for_documents(): void
    {
        config(['empower.uploads.disk' => 'public']);

        $this->expectException(RuntimeException::class);
        app(DocumentStorageService::class)->temporaryUrl('requirements/1/anything.pdf');
    }

    public function test_a_disk_that_cannot_sign_gives_no_link_rather_than_a_permanent_one(): void
    {
        // A private remote-style disk that cannot mint signed links: before the
        // fix this fell back to a plain, permanent URL.
        Storage::extend('unsignable', function ($app, array $config) {
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        });
        config([
            'filesystems.disks.unsignable' => [
                'driver' => 'unsignable',
                'root' => storage_path('framework/testing/disks/unsignable'),
                'visibility' => 'private',
            ],
            'empower.uploads.disk' => 'unsignable',
        ]);

        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->plainRequirementType();
        Storage::disk('unsignable')->put('requirements/'.$applicant->id.'/file.pdf', $this->pdfBytes());
        ApplicantRequirement::updateOrCreate(
            ['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id],
            ['status' => 'submitted', 'file_path' => 'requirements/'.$applicant->id.'/file.pdf', 'file_name' => 'file.pdf']
        );

        Sanctum::actingAs($hr);
        $response = $this->getJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/download")
            ->assertStatus(503);

        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertStringNotContainsString('requirements/'.$applicant->id, $response->getContent());

        (new Filesystem)->deleteDirectory(storage_path('framework/testing/disks/unsignable'));
    }

    public function test_only_authorised_people_get_a_document_link(): void
    {
        Storage::fake('documents');
        $hr = $this->hrUser();
        $owner = $this->applicant($hr, ['first_name' => 'Owner']);
        $stranger = $this->applicant($hr, ['first_name' => 'Stranger']);
        $type = $this->plainRequirementType();

        ApplicantRequirement::updateOrCreate(
            ['applicant_id' => $owner->id, 'requirement_type_id' => $type->id],
            ['status' => 'submitted', 'file_path' => 'requirements/'.$owner->id.'/doc.pdf', 'file_name' => 'doc.pdf']
        );
        $staffRoute = "/api/v1/applicants/{$owner->id}/requirements/{$type->id}/download";

        // Nobody signed in.
        $this->getJson($staffRoute)->assertUnauthorized();

        // The owner reads their own copy through the portal only.
        Sanctum::actingAs($this->portalUserFor($owner));
        $this->getJson("/api/v1/portal/documents/{$type->id}/download")->assertOk()->assertJsonStructure(['data' => ['url']]);
        $this->getJson($staffRoute)->assertForbidden();

        // Another applicant cannot reach it by any route.
        Sanctum::actingAs($this->portalUserFor($stranger));
        $this->getJson($staffRoute)->assertForbidden();
        $this->getJson("/api/v1/portal/documents/{$type->id}/download")->assertNotFound();

        // A coordinator holds no document permission.
        $coordinator = User::factory()->create(['email' => 'coord.'.uniqid().'@cde.test', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        Sanctum::actingAs($coordinator);
        $this->getJson($staffRoute)->assertForbidden();
        $this->getJson("/api/v1/documents/file/{$owner->requirements()->first()->id}")->assertForbidden();

        // HR does.
        Sanctum::actingAs($hr);
        $this->getJson($staffRoute)->assertOk()->assertJsonStructure(['data' => ['url', 'expires_in_minutes']]);
    }

    // ------------------------------------------------------------------ R-02

    public function test_a_valid_pdf_is_stored_under_a_random_name_with_its_detected_extension(): void
    {
        Storage::fake('documents');
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->plainRequirementType();

        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->post("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => $this->realUpload('Birth Certificate.pdf', $this->pdfBytes()),
        ], ['Accept' => 'application/json'])->assertOk();

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->firstOrFail();

        $this->assertMatchesRegularExpression(
            '#^requirements/'.$applicant->id.'/[0-9a-f-]{36}\.pdf$#',
            $requirement->file_path
        );
        $this->assertSame('application/pdf', $requirement->file_mime);
        $this->assertSame('Birth Certificate.pdf', $requirement->file_name);
        Storage::disk('documents')->assertExists($requirement->file_path);
    }

    public function test_a_pdf_named_as_a_web_page_is_refused(): void
    {
        Storage::fake('documents');
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->plainRequirementType();

        Sanctum::actingAs($this->portalUserFor($applicant));
        $response = $this->post("/api/v1/portal/documents/{$type->id}/upload", [
            // PDF bytes followed by script: passes a content check alone, and
            // was stored as .html before the fix.
            'file' => $this->realUpload('notes.html', $this->pdfBytes().'<html><script>alert(1)</script></html>'),
        ], ['Accept' => 'application/json']);

        $this->assertTrue($response->status() >= 400 && $response->status() < 500, 'Expected a refusal, got '.$response->status());
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertNull(ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->value('file_path'));
    }

    public function test_content_that_does_not_match_an_allowed_type_is_refused(): void
    {
        Storage::fake('documents');
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->plainRequirementType();
        Sanctum::actingAs($this->portalUserFor($applicant));

        $cases = [
            'HTML posing as a PDF' => $this->realUpload('scan.pdf', '<html><body><script>alert(1)</script></body></html>'),
            'SVG image' => $this->realUpload('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'PHP script' => $this->realUpload('shell.php', '<?php echo 1;'),
            'Executable' => $this->realUpload('setup.exe', "MZ\x90\x00\x03\x00\x00\x00"),
            'Broken image' => $this->realUpload('photo.jpg', "\xFF\xD8\xFF\xE0garbage-not-a-jpeg"),
        ];

        foreach ($cases as $label => $file) {
            $response = $this->post("/api/v1/portal/documents/{$type->id}/upload", ['file' => $file], ['Accept' => 'application/json']);

            $this->assertTrue(
                $response->status() >= 400 && $response->status() < 500,
                "{$label} should be refused, got {$response->status()}"
            );
        }

        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_a_misnamed_image_is_stored_by_what_it_actually_is(): void
    {
        Storage::fake('documents');
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->plainRequirementType();

        $source = UploadedFile::fake()->image('source.png', 300, 300);
        $png = file_get_contents($source->getRealPath());

        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->post("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => $this->realUpload('scan.pdf', $png),
        ], ['Accept' => 'application/json'])->assertOk();

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->firstOrFail();

        $this->assertStringEndsWith('.png', $requirement->file_path);
        $this->assertSame('image/png', $requirement->file_mime);
    }

    public function test_a_hostile_file_name_never_reaches_the_storage_path(): void
    {
        Storage::fake('documents');
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->plainRequirementType();

        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->post("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => $this->realUpload('../../../public/index<script>.pdf', $this->pdfBytes()),
        ], ['Accept' => 'application/json'])->assertOk();

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->firstOrFail();

        $this->assertMatchesRegularExpression('#^requirements/'.$applicant->id.'/[0-9a-f-]{36}\.pdf$#', $requirement->file_path);
        $this->assertStringNotContainsString('..', $requirement->file_path);
        $this->assertStringNotContainsString('/', $requirement->file_name);
        $this->assertStringNotContainsString('<', $requirement->file_name);
    }
}
