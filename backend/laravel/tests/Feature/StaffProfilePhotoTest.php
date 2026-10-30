<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProfilePhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * A staff member's own profile picture - administrator, HR, or coordinator -
 * set from Settings and shown with their account.
 *
 * The same checks and the same storage as an applicant's picture
 * (ProfilePhotoTest), but kept on the account, because staff have no
 * applicant record to hold it. A portal account still sets its picture from
 * the portal alone.
 */
class StaffProfilePhotoTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoClients();
        Storage::fake('documents');
    }

    private function upload(UploadedFile $file, array $extra = [])
    {
        return $this->post('/api/v1/auth/profile/photo', array_merge(['photo' => $file], $extra), [
            'Accept' => 'application/json',
        ]);
    }

    private function storedPhotos(User $user): array
    {
        return Storage::disk('documents')->files(ProfilePhotoService::STAFF_FOLDER.'/'.$user->id);
    }

    public function test_admin_hr_and_coordinators_each_set_their_own_picture(): void
    {
        $accounts = [
            'admin' => $this->admin,
            'HR' => $this->hr,
            'coordinator' => $this->coordinatorFor($this->clientA),
        ];

        foreach ($accounts as $role => $user) {
            $this->actAs($user);

            $response = $this->upload(UploadedFile::fake()->image('me.png', 300, 300))
                ->assertOk()
                ->assertJsonPath('message', 'Your profile picture has been updated.');

            $path = $user->fresh()->profile_photo_path;

            $this->assertStringStartsWith(ProfilePhotoService::STAFF_FOLDER."/{$user->id}/", (string) $path, $role);
            $this->assertStringEndsWith('.png', $path);
            Storage::disk('documents')->assertExists($path);
            $this->assertStringContainsString(basename($path), $response->json('data.photo_url'));

            // Kept with the account, so the header still shows it after a
            // refresh or a new sign-in.
            $this->assertStringContainsString(
                basename($path),
                $this->getJson('/api/v1/auth/me')->assertOk()->json('data.photo_url')
            );
        }
    }

    public function test_replacing_a_picture_removes_the_old_file_once_the_new_one_is_saved(): void
    {
        $this->actAs($this->hr);

        $this->upload(UploadedFile::fake()->image('first.jpg', 300, 300))->assertOk();
        $first = $this->hr->fresh()->profile_photo_path;

        $this->upload(UploadedFile::fake()->image('second.webp', 300, 300))->assertOk();
        $second = $this->hr->fresh()->profile_photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('documents')->assertMissing($first);
        $this->assertSame([$second], $this->storedPhotos($this->hr));
    }

    public function test_files_that_are_not_pictures_are_refused(): void
    {
        $this->actAs($this->admin);

        $refused = [
            'a PDF' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
            'text named as a picture' => UploadedFile::fake()->createWithContent('face.jpg', 'this is not an image'),
            'an oversized picture' => UploadedFile::fake()->image('huge.jpg', 400, 400)->size(6000),
            'a picture too small to show' => UploadedFile::fake()->image('tiny.png', 20, 20),
        ];

        foreach ($refused as $case => $file) {
            $this->upload($file)->assertStatus(422)->assertJsonValidationErrors('photo');
            $this->assertNull($this->admin->fresh()->profile_photo_path, "{$case} was saved");
        }

        $this->assertSame([], $this->storedPhotos($this->admin));
    }

    public function test_the_picture_is_always_the_signed_in_accounts_own(): void
    {
        $other = $this->hrUser();
        $this->actAs($this->hr);

        // Naming another account in the request changes nothing.
        $this->upload(UploadedFile::fake()->image('face.jpg', 300, 300), [
            'user_id' => $other->id,
            'id' => $other->id,
        ])->assertOk();

        $this->assertNotNull($this->hr->fresh()->profile_photo_path);
        $this->assertNull($other->fresh()->profile_photo_path);
        $this->assertSame([], $this->storedPhotos($other));

        // Neither the account's own details form nor an administrator editing
        // the account can point it at a file: the column is not fillable.
        $mine = $this->hr->fresh()->profile_photo_path;
        $this->patchJson('/api/v1/auth/profile', ['profile_photo_path' => 'profile-photos/staff/1/x.jpg'])->assertOk();
        $this->assertSame($mine, $this->hr->fresh()->profile_photo_path);

        $this->actAs($this->admin);
        $this->patchJson("/api/v1/users/{$other->id}", ['profile_photo_path' => 'profile-photos/staff/1/x.jpg']);
        $this->assertNull($other->fresh()->profile_photo_path);

        // The storage key never leaves the server; screens get a signed link.
        $this->assertArrayNotHasKey('profile_photo_path', $this->hr->fresh()->toArray());
    }

    public function test_a_portal_account_sets_its_picture_from_the_portal_only(): void
    {
        $applicant = $this->applicant($this->hr);
        $portal = User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'password' => Hash::make('password'),
        ]);
        $portal->assignRole('portal');
        $this->actAs($portal);

        $this->upload(UploadedFile::fake()->image('face.jpg', 300, 300))->assertForbidden();

        $this->assertNull($portal->fresh()->profile_photo_path);
        $this->assertNull($applicant->fresh()->profile_photo_path);
        $this->assertSame([], Storage::disk('documents')->allFiles(ProfilePhotoService::FOLDER));
    }

    public function test_a_staff_account_without_a_picture_has_no_link(): void
    {
        $this->actAs($this->coordinatorFor($this->clientB));

        $this->assertNull($this->getJson('/api/v1/auth/me')->assertOk()->json('data.photo_url'));
    }
}
