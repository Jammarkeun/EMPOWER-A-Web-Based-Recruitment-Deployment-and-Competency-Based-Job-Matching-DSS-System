<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Moving existing documents off the public disk (audit R-01).
 *
 * The keys stay the same, so no database row changes; source copies are only
 * removed when asked, and only after the copy verifies.
 */
class MoveDocumentsCommandTest extends TestCase
{
    public function test_documents_are_copied_and_the_source_kept_until_asked(): void
    {
        Storage::fake('public');
        Storage::fake('documents');
        Storage::disk('public')->put('requirements/3/a.pdf', '%PDF-1.4 a');
        Storage::disk('public')->put('profile-photos/3/p.png', 'png-bytes');
        Storage::disk('public')->put('unrelated/readme.txt', 'not a document');

        $this->artisan('empower:move-documents', ['--from' => 'public', '--to' => 'documents'])->assertSuccessful();

        Storage::disk('documents')->assertExists(['requirements/3/a.pdf', 'profile-photos/3/p.png']);
        Storage::disk('documents')->assertMissing('unrelated/readme.txt');
        Storage::disk('public')->assertExists('requirements/3/a.pdf');

        $this->artisan('empower:move-documents', ['--from' => 'public', '--to' => 'documents', '--delete-source' => true])
            ->assertSuccessful();

        Storage::disk('public')->assertMissing(['requirements/3/a.pdf', 'profile-photos/3/p.png']);
        Storage::disk('public')->assertExists('unrelated/readme.txt');
        $this->assertSame('%PDF-1.4 a', Storage::disk('documents')->get('requirements/3/a.pdf'));
    }

    public function test_it_refuses_to_move_documents_onto_a_public_disk(): void
    {
        Storage::fake('documents');

        $this->artisan('empower:move-documents', ['--from' => 'documents', '--to' => 'public'])->assertFailed();
    }

    public function test_a_dry_run_copies_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('documents');
        Storage::disk('public')->put('requirements/3/a.pdf', '%PDF-1.4 a');

        $this->artisan('empower:move-documents', ['--from' => 'public', '--to' => 'documents', '--dry-run' => true])
            ->assertSuccessful();

        Storage::disk('documents')->assertMissing('requirements/3/a.pdf');
    }
}
