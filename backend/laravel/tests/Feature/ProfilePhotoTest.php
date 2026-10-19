<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\User;
use App\Services\CoordinatorAssignmentService;
use App\Services\DeploymentService;
use App\Services\ProfilePhotoService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * An applicant's profile picture, from upload to everyone who may see it.
 *
 * The applicant sets and replaces their own picture through the portal only,
 * and the picture then travels inside the responses each role already
 * receives - so it reaches exactly the people who could already see that
 * applicant, and nobody else.
 */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Storage::fake('documents');

        $this->hr = $this->hrUser();
    }

    private function portalUserFor(Applicant $applicant): User
    {
        $user = User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('portal');

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function upload(UploadedFile $file, array $extra = [])
    {
        return $this->post('/api/v1/portal/profile/photo', array_merge(['photo' => $file], $extra), [
            'Accept' => 'application/json',
        ]);
    }

    private function storedPhotos(Applicant $applicant): array
    {
        return Storage::disk('documents')->files(ProfilePhotoService::FOLDER.'/'.$applicant->id);
    }

    public function test_an_applicant_uploads_a_picture_and_their_account_shows_it(): void
    {
        $applicant = $this->applicant($this->hr, ['first_name' => 'Andrea', 'last_name' => 'Santos']);
        $this->actAs($this->portalUserFor($applicant));

        $response = $this->upload(UploadedFile::fake()->image('andrea.jpg', 400, 400))
            ->assertOk()
            ->assertJsonPath('message', 'Your profile picture has been updated.');

        $path = $applicant->fresh()->profile_photo_path;

        $this->assertNotNull($response->json('data.photo_url'));
        $this->assertStringStartsWith(ProfilePhotoService::FOLDER."/{$applicant->id}/", $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('documents')->assertExists($path);

        // The account itself carries the picture, so it is there after a refresh
        // or a new sign-in, not only on the screen that uploaded it.
        $me = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.photo_url');
        $this->assertNotNull($me);
        $this->assertStringContainsString(basename($path), $me);
    }

    public function test_replacing_a_picture_removes_the_old_file_once_the_new_one_is_saved(): void
    {
        $applicant = $this->applicant($this->hr);
        $this->actAs($this->portalUserFor($applicant));

        $this->upload(UploadedFile::fake()->image('first.png', 300, 300))->assertOk();
        $first = $applicant->fresh()->profile_photo_path;

        $this->upload(UploadedFile::fake()->image('second.webp', 300, 300))->assertOk();
        $second = $applicant->fresh()->profile_photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('documents')->assertMissing($first);
        Storage::disk('documents')->assertExists($second);
        $this->assertSame([$second], $this->storedPhotos($applicant));
    }

    public function test_files_that_are_not_pictures_are_refused_and_the_current_picture_is_kept(): void
    {
        $applicant = $this->applicant($this->hr);
        $this->actAs($this->portalUserFor($applicant));

        $this->upload(UploadedFile::fake()->image('face.jpg', 300, 300))->assertOk();
        $current = $applicant->fresh()->profile_photo_path;

        $refused = [
            'a PDF' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
            'text named as a picture' => UploadedFile::fake()->createWithContent('face.jpg', 'this is not an image'),
            'a script named as a picture' => UploadedFile::fake()->createWithContent('face.png', '<?php echo "hi"; ?>'),
            'a damaged picture' => UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64)),
            'an oversized picture' => UploadedFile::fake()->image('huge.jpg', 400, 400)->size(6000),
            'a picture too small to show' => UploadedFile::fake()->image('tiny.png', 20, 20),
        ];

        foreach ($refused as $case => $file) {
            $this->upload($file)->assertStatus(422)->assertJsonValidationErrors('photo');
            $this->assertSame($current, $applicant->fresh()->profile_photo_path, "{$case} replaced the picture");
        }

        $this->upload(UploadedFile::fake()->create('empty'))->assertStatus(422);
        $this->postJson('/api/v1/portal/profile/photo', [])->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertSame([$current], $this->storedPhotos($applicant));
    }

    public function test_an_applicant_cannot_change_another_applicants_picture(): void
    {
        $mine = $this->applicant($this->hr, ['first_name' => 'Mine']);
        $theirs = $this->applicant($this->hr, ['first_name' => 'Theirs']);
        $this->actAs($this->portalUserFor($mine));

        // Naming the other applicant in the request changes nothing: the target
        // is always the signed-in applicant's own record.
        $this->upload(UploadedFile::fake()->image('face.jpg', 300, 300), [
            'applicant_id' => $theirs->id,
            'id' => $theirs->id,
        ])->assertOk();

        $this->assertNotNull($mine->fresh()->profile_photo_path);
        $this->assertNull($theirs->fresh()->profile_photo_path);
        $this->assertSame([], $this->storedPhotos($theirs));

        // Staff edits cannot set the picture either: the column is not fillable.
        $this->actAs($this->hr);
        $this->patchJson("/api/v1/applicants/{$theirs->id}", ['profile_photo_path' => 'profile-photos/1/x.jpg']);
        $this->assertNull($theirs->fresh()->profile_photo_path);

        // And the upload endpoint belongs to the portal alone.
        $this->upload(UploadedFile::fake()->image('face.jpg', 300, 300))->assertForbidden();
    }

    public function test_staff_see_the_picture_wherever_they_already_see_the_applicant(): void
    {
        $withPhoto = $this->applicant($this->hr, ['first_name' => 'Andrea', 'last_name' => 'Pictured']);
        $withoutPhoto = $this->applicant($this->hr, ['first_name' => 'Jonas', 'last_name' => 'Plain']);
        app(ProfilePhotoService::class)->replace($withPhoto, UploadedFile::fake()->image('a.jpg', 300, 300));
        $name = basename($withPhoto->fresh()->profile_photo_path);

        $this->actAs($this->hr);

        $list = collect($this->getJson('/api/v1/applicants?per_page=50')->assertOk()->json('data'))->keyBy('id');
        $this->assertStringContainsString($name, $list[$withPhoto->id]['photo_url']);
        $this->assertNull($list[$withoutPhoto->id]['photo_url']);

        $this->assertStringContainsString(
            $name,
            $this->getJson("/api/v1/applicants/{$withPhoto->id}")->assertOk()->json('data.applicant.photo_url')
        );

        // include_empty: neither applicant has a document on file yet.
        $documents = collect($this->getJson('/api/v1/documents/applicants?include_empty=1')->assertOk()->json('data'))->keyBy('id');
        $this->assertStringContainsString($name, $documents[$withPhoto->id]['photo_url']);
        $this->assertNull($documents[$withoutPhoto->id]['photo_url']);

        $search = $this->getJson('/api/v1/search?q=Pictured')->assertOk()->json('data.groups');
        $applicants = collect($search)->firstWhere('type', 'applicants');
        $this->assertStringContainsString($name, $applicants['results'][0]['image']);

        // An applicant still cannot open somebody else's record to reach it.
        $this->actAs($this->portalUserFor($withoutPhoto));
        $this->getJson("/api/v1/applicants/{$withPhoto->id}")->assertForbidden();
    }

    public function test_a_coordinator_sees_the_picture_of_a_worker_at_their_own_client_only(): void
    {
        $admin = $this->adminUser();
        $clientA = $this->clientCompany($this->hr, ['company_name' => 'Client A']);
        $clientB = $this->clientCompany($this->hr, ['company_name' => 'Client B']);
        $worker = $this->place($clientA, 'Rosa', 'Pictured');

        app(ProfilePhotoService::class)->replace($worker->applicant, UploadedFile::fake()->image('r.jpg', 300, 300));
        $name = basename($worker->applicant->fresh()->profile_photo_path);

        $coordinatorA = $this->coordinator($clientA, $admin);
        $this->actAs($coordinatorA);

        $rows = collect($this->getJson('/api/v1/coordinator/employees')->assertOk()->json('data'))->keyBy('id');
        $this->assertStringContainsString($name, $rows[$worker->id]['photo_url']);
        $this->assertStringContainsString(
            $name,
            $this->getJson("/api/v1/coordinator/employees/{$worker->id}")->assertOk()->json('data.employee.photo_url')
        );

        // The applicant record itself stays out of a coordinator's reach.
        $this->getJson("/api/v1/applicants/{$worker->applicant_id}")->assertForbidden();

        // A coordinator for another client does not reach this worker at all.
        $this->actAs($this->coordinator($clientB, $admin));
        $this->assertNotContains(
            $worker->id,
            array_column($this->getJson('/api/v1/coordinator/employees')->assertOk()->json('data'), 'id')
        );
        $this->assertContains(
            $this->getJson("/api/v1/coordinator/employees/{$worker->id}")->status(),
            [403, 404]
        );
    }

    public function test_an_applicant_without_a_picture_has_no_link_rather_than_a_broken_one(): void
    {
        $applicant = $this->applicant($this->hr);
        $this->actAs($this->portalUserFor($applicant));

        $this->assertNull($this->getJson('/api/v1/auth/me')->assertOk()->json('data.photo_url'));

        $this->actAs($this->hr);
        $this->assertNull($this->getJson('/api/v1/auth/me')->assertOk()->json('data.photo_url'));
    }

    public function test_a_failed_save_keeps_the_previous_picture_and_leaves_no_orphan(): void
    {
        $applicant = $this->applicant($this->hr);
        $this->actAs($this->portalUserFor($applicant));

        $this->upload(UploadedFile::fake()->image('first.jpg', 300, 300))->assertOk();
        $first = $applicant->fresh()->profile_photo_path;

        // The file stores, then the record cannot be saved - with the kind of
        // error a database really raises, whose code is SQLSTATE text, not a
        // number. That is exactly what happened against a database the
        // migration had not reached yet.
        Applicant::saving(function () {
            $driverError = new PDOException('SQLSTATE[42S22]: Column not found');
            (fn () => $this->code = '42S22')->call($driverError);

            throw new QueryException('sqlite', 'update applicants set profile_photo_path = ?', [], $driverError);
        });

        $this->upload(UploadedFile::fake()->image('second.jpg', 300, 300))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Your picture could not be saved, so your current picture is unchanged. Please try again.');

        $this->assertSame($first, Applicant::query()->find($applicant->id)->profile_photo_path);
        $this->assertSame([$first], $this->storedPhotos($applicant));
    }

    private function place(ClientCompany $company, string $first, string $last): Employee
    {
        $department = $this->department($company, $this->hr, ['department_code' => 'D'.uniqid()]);
        $request = $this->jobRequest($department, $this->hr, ['workers_needed' => 5]);
        $applicant = $this->applicant($this->hr, [
            'first_name' => $first,
            'last_name' => $last,
            'current_status' => 'ready_for_deployment',
        ]);
        $this->verifyRequirements($applicant, $this->hr, 'all');
        $this->completeTraining($applicant, $this->hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->subMonth()->toDateString(),
            'supervisor_name' => 'Line Supervisor',
        ], $this->hr)->employee;
    }

    private function coordinator(ClientCompany $company, User $admin): User
    {
        $user = User::factory()->create([
            'email' => 'coordinator.'.uniqid().'@cdemanpower.local',
            'user_type' => 'coordinator',
            'password' => Hash::make('Coordinator-Pass-2026!'),
        ]);
        $user->assignRole('coordinator');
        app(CoordinatorAssignmentService::class)->assign($user, $company, $admin);

        return $user;
    }
}
