<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use App\Notifications\DeploymentRecorded;
use App\Notifications\PasswordChanged;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * A portal login reads the notifications for every record that is the
 * person's own (audit S-03).
 *
 * Notifications are addressed to the record an event concerns: a deployment
 * or status change to the applicant record, a password change to the login.
 * An employee's login used to be resolved to their employee record alone - to
 * which nothing is ever addressed - so the employee portal showed nothing at
 * all, not even their own password-change notice.
 */
class NotificationScopeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function deployedEmployee(User $hr): Employee
    {
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return app(DeploymentService::class)->deploy(
            $applicant,
            $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr),
            ['deployment_date' => now()->toDateString()],
            $hr,
        )->employee;
    }

    /** As UserController creates it: linked to the employee record only. */
    private function employeeLogin(Employee $employee): User
    {
        return tap(User::factory()->create([
            'email' => 'employee'.$employee->id.'.'.uniqid().'@example.test',
            'user_type' => 'employee',
            'employee_id' => $employee->id,
        ]))->assignRole('portal');
    }

    private function applicantLogin(Applicant $applicant): User
    {
        return tap(User::factory()->create([
            'email' => 'applicant'.$applicant->id.'.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]))->assignRole('portal');
    }

    /** @return list<string> */
    private function idsFor($notifiable): array
    {
        return DatabaseNotification::where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->pluck('id')
            ->all();
    }

    public function test_an_employee_sees_what_was_sent_to_their_applicant_record_and_their_login(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        $login = $this->employeeLogin($employee);
        $login->notify(new PasswordChanged());

        $expected = array_merge($this->idsFor($employee->applicant), $this->idsFor($login));
        $this->assertNotEmpty($this->idsFor($employee->applicant), 'The deployment notified the applicant record.');

        Sanctum::actingAs($login);

        $response = $this->getJson('/api/v1/notifications')->assertOk();
        $this->assertEqualsCanonicalizing($expected, collect($response->json('data.notifications'))->pluck('id')->all());
        $response->assertJsonPath('data.unread_count', count($expected));

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', count($expected));
    }

    public function test_an_employee_can_mark_any_of_their_own_notifications_read(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        $login = $this->employeeLogin($employee);
        $login->notify(new PasswordChanged());

        Sanctum::actingAs($login);

        $sentToApplicantRecord = $this->idsFor($employee->applicant)[0];
        $this->postJson("/api/v1/notifications/{$sentToApplicantRecord}/read")->assertOk();
        $this->assertNotNull(DatabaseNotification::find($sentToApplicantRecord)->read_at);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_an_applicant_sees_notices_sent_to_their_login_too(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $login = $this->applicantLogin($applicant);
        $login->notify(new PasswordChanged());

        Sanctum::actingAs($login);

        $this->assertSame(
            $this->idsFor($login),
            collect($this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications'))->pluck('id')->all()
        );
    }

    public function test_nobody_reads_or_marks_another_persons_notifications(): void
    {
        $hr = $this->hrUser();
        $first = $this->deployedEmployee($hr);
        $second = $this->deployedEmployee($hr);
        $secondLogin = $this->employeeLogin($second);

        $hr->notify(new PasswordChanged());
        $firstsNotification = $this->idsFor($first->applicant)[0];

        Sanctum::actingAs($secondLogin);

        $visible = collect($this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications'))->pluck('id');
        $this->assertFalse($visible->contains($firstsNotification));
        $this->assertTrue($visible->intersect($this->idsFor($hr))->isEmpty());

        $this->postJson("/api/v1/notifications/{$firstsNotification}/read")->assertNotFound();
        $this->assertNull(DatabaseNotification::find($firstsNotification)->read_at);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertNull(DatabaseNotification::find($firstsNotification)->read_at);

        // A staff login reads its own and no one else's.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($hr);
        $this->assertEqualsCanonicalizing(
            $this->idsFor($hr),
            collect($this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications'))->pluck('id')->all()
        );
    }

    public function test_a_malformed_notification_id_is_simply_not_found(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->postJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();
    }

    /**
     * Audit WF-13: the login that reads a notification is the one whose
     * preferences decide whether it is sent. Preferences were looked up by
     * `applicant_id` only, so this login's muted categories were ignored for
     * everything addressed to the applicant record.
     */
    public function test_an_employee_login_linked_only_to_the_employee_record_has_its_preferences_honoured(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        $login = $this->employeeLogin($employee);
        $login->forceFill(['notification_preferences' => ['application' => false]])->save();

        $this->assertTrue(User::portalAccountFor($employee->applicant)->is($login));
        $this->assertTrue(User::portalAccountFor($employee)->is($login));

        // The deployment already notified the applicant record, before this
        // login existed; those stay as they are.
        Sanctum::actingAs($login);
        $before = $this->getJson('/api/v1/notifications/unread-count')->assertOk()->json('data');

        // Muted category: not delivered.
        $employee->applicant->notify(new ApplicationStatusChanged($employee->applicant, 'active'));
        // A category they did not mute still arrives, and the badge counts it.
        $employee->applicant->notify(new DeploymentRecorded($employee->activeDeployment()));

        $after = $this->getJson('/api/v1/notifications/unread-count')->assertOk()->json('data');

        $this->assertSame($before['unread_count'] + 1, $after['unread_count']);
        $this->assertSame($before['by_category']['application'] ?? 0, $after['by_category']['application'] ?? 0);
        $this->assertSame(($before['by_category']['deployment'] ?? 0) + 1, $after['by_category']['deployment']);
    }

    public function test_a_record_with_no_portal_login_still_accrues_its_notifications(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);

        $this->assertNull(User::portalAccountFor($applicant));

        $applicant->notify(new ApplicationStatusChanged($applicant, 'initial_screening'));
        $this->assertCount(1, $this->idsFor($applicant), 'Visible the day an account is created for them.');
    }
}
