<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ClientCompany;
use App\Models\CoordinatorAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Rules\PasswordPolicy;
use App\Rules\PersonName;
use App\Rules\PhilippineMobileNumber;
use App\Services\AuditService;
use App\Services\CoordinatorAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Administrator management of system users, roles, and portal access.
 *
 * Every action here is gated on the users.* permissions, which only the
 * Administrator role holds. HR runs recruitment; it does not decide who can log
 * in, which keeps the person being audited separate from the person who can
 * grant themselves more access.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly CoordinatorAssignmentService $assignments,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manageUsers');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'user_type' => ['nullable', Rule::in(['admin', 'hr', 'coordinator', 'applicant', 'employee'])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        // A login made at registration keeps user_type "applicant" after its
        // owner is deployed, and the portal shows them their employment
        // through the applicant record (User::portalEmployee). The two portal
        // filters follow the same rule, so a deployed worker is listed - and
        // labelled - as an employee login rather than only under "applicant".
        $reachesEmployee = fn ($q) => $q->whereNotNull('employee_id')
            ->orWhereHas('applicant', fn ($a) => $a->whereHas('employee'));

        $users = User::query()
            ->with(['roles', 'activeCoordinatorAssignment.company', 'applicant:id', 'applicant.employee:id,applicant_id'])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereContains('first_name', $v)
                ->orWhereContains('last_name', $v)
                ->orWhereContains('email', $v)))
            ->when($filters['user_type'] ?? null, fn ($q, $v) => match ($v) {
                'employee' => $q->whereIn('user_type', User::PORTAL_TYPES)->where($reachesEmployee),
                'applicant' => $q->where('user_type', 'applicant')->whereNot($reachesEmployee),
                default => $q->where('user_type', $v),
            })
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('user_type')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(
            $users->through(fn (User $user) => $this->present($user)),
            'Users retrieved'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('createUsers');

        PhilippineMobileNumber::normalizeRequest($request, 'mobile_number');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100', new PersonName()],
            'middle_name' => ['nullable', 'string', 'max:100', new PersonName()],
            'last_name' => ['required', 'string', 'max:100', new PersonName()],
            'email' => ['required', 'email:rfc,filter', 'max:190', 'unique:users,email'],
            'mobile_number' => ['nullable', 'string', 'max:30', new PhilippineMobileNumber()],
            'user_type' => ['required', Rule::in(['admin', 'hr', 'coordinator'])],
            // A coordinator's role is fixed; staff choose admin or hr.
            'role' => ['required_unless:user_type,coordinator', 'nullable', 'string', 'exists:roles,name'],
            // Every coordinator covers a client from the moment the account exists.
            'client_company_id' => ['required_if:user_type,coordinator', 'nullable', 'integer', 'exists:client_companies,id'],
            'assignment_remarks' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy()],
        ], [
            'client_company_id.required_if' => 'Choose the client company this coordinator will cover.',
        ]);

        $role = $this->roleFor($data['user_type'], $data['role'] ?? null);

        // Staff and coordinator accounts only. Portal logins are created from
        // the applicant or employee record through provisionPortalAccess, so an
        // account can never be linked to the wrong person by typing an ID.
        $user = DB::transaction(function () use ($data, $role, $request) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'mobile_number' => $data['mobile_number'] ?? null,
                'password' => Hash::make($data['password']),
                'user_type' => $data['user_type'],
                'is_active' => true,
            ]);

            // Set outside the mass assignment: a verification timestamp is a system
            // fact, and keeping it out of $fillable means no request payload can
            // ever claim an address is verified. The password was chosen by the
            // administrator, so the new staff member replaces it at their first
            // sign-in (audit SEC-07).
            $user->forceFill(['email_verified_at' => now(), 'must_change_password' => true])->save();

            $user->syncRoles([$role]);

            $this->audit->record('create', 'users', User::class, $user->id, null, [
                'email' => $data['email'],
                'user_type' => $data['user_type'],
                'role' => $role,
            ]);

            if ($data['user_type'] === 'coordinator') {
                $this->assignments->assign(
                    $user,
                    ClientCompany::findOrFail($data['client_company_id']),
                    $request->user(),
                    $data['assignment_remarks'] ?? null
                );
            }

            return $user;
        });

        return ApiResponse::created(
            $this->present($user->fresh(['roles', 'activeCoordinatorAssignment.company'])),
            'User created'
        );
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('manageUsers');

        return ApiResponse::success($this->present($user->load('roles', 'applicant', 'employee', 'activeCoordinatorAssignment.company')));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('updateUsers');

        PhilippineMobileNumber::normalizeRequest($request, 'mobile_number');

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100', new PersonName()],
            'middle_name' => ['nullable', 'string', 'max:100', new PersonName()],
            'last_name' => ['sometimes', 'string', 'max:100', new PersonName()],
            'email' => ['sometimes', 'email:rfc,filter', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile_number' => ['nullable', 'string', 'max:30', new PhilippineMobileNumber()],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
        ]);

        // Every check runs before anything is written, so a refused role change
        // cannot leave the name or email half-updated.
        if (isset($data['role'])) {
            // Changing what an account may do is its own permission.
            $this->authorize('manageRoles');

            // The role must fit the kind of account: a coordinator stays a
            // coordinator, a portal account stays a portal account, and staff
            // cannot be given the coordinator role, which would leave them
            // without an assignment to scope them.
            $data['role'] = $this->roleFor($user->user_type, $data['role']);
            $this->assertNotLastAdministrator($user, $data['role']);
        }

        $before = $user->only(['first_name', 'last_name', 'email', 'mobile_number']);

        DB::transaction(function () use ($user, $data) {
            $user->update(collect($data)->except('role')->all());

            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
            }
        });

        $this->audit->recordUpdate('users', User::class, $user->id, $before, $data);

        return ApiResponse::success($this->present($user->fresh('roles')), 'User updated');
    }

    /**
     * Activate or deactivate an account.
     *
     * Deactivating also destroys the user's tokens, so access ends immediately
     * rather than lasting until their current session expires — which is the
     * gap most offboarding processes leave open.
     */
    public function setStatus(Request $request, User $user): JsonResponse
    {
        $this->authorize('updateUsers');

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $data['is_active']) {
            if ($user->id === $request->user()->id) {
                return ApiResponse::error('You cannot deactivate your own account.', 400);
            }

            $this->assertNotLastAdministrator($user, null);
            $user->tokens()->delete();
        }

        $user->forceFill(['is_active' => $data['is_active']])->save();

        $this->audit->record(
            'update',
            'users',
            User::class,
            $user->id,
            ['is_active' => ! $data['is_active']],
            ['is_active' => $data['is_active'], 'reason' => $data['reason'] ?? null]
        );

        return ApiResponse::success(
            $this->present($user->fresh('roles')),
            $data['is_active'] ? 'Account reactivated' : 'Account deactivated and signed out'
        );
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('updateUsers');

        /*
         * The office issues a temporary password; it never chooses a working
         * one (audit SEC-07, decided 2026-10-19). The administrator used to type
         * the new password, and so knew it for as long as it stayed in use. Now
         * the system generates it, shows it once, and the holder has to replace
         * it before the account does anything else (EnsurePasswordIsCurrent).
         */
        $temporaryPassword = Str::password(14);

        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ])->save();

        // Every existing session is ended: a password reset that leaves old
        // sessions alive does not actually lock anyone out.
        $user->tokens()->delete();

        $this->audit->record('update', 'users', User::class, $user->id, null, [
            'password' => '[redacted]',
            'temporary' => true,
            'sessions_revoked' => true,
        ]);

        $user->notify(new PasswordChanged());

        return ApiResponse::success([
            // Shown once. Only its hash is kept.
            'temporary_password' => $temporaryPassword,
            'notice' => 'Give this to '.$user->full_name.' in person or over a channel you trust. It works only to choose a new password, the first time they sign in.',
        ], 'Temporary password issued. The user has been signed out everywhere.');
    }

    /**
     * Creates a portal login for an applicant or employee.
     *
     * Driven from the person's record rather than from a form, so the account is
     * always linked to the right individual. Returns a generated temporary
     * password once — it is not stored in readable form and cannot be shown
     * again.
     */
    public function provisionPortalAccess(Request $request): JsonResponse
    {
        $this->authorize('createUsers');

        $data = $request->validate([
            'applicant_id' => ['required_without:employee_id', 'nullable', 'integer', 'exists:applicants,id'],
            'employee_id' => ['required_without:applicant_id', 'nullable', 'integer', 'exists:employees,id'],
            'email' => ['required', 'email:rfc,filter', 'max:190', 'unique:users,email'],
        ]);

        if (isset($data['applicant_id'], $data['employee_id'])) {
            throw ValidationException::withMessages([
                'applicant_id' => ['Provide either an applicant or employee, not both.'],
                'employee_id' => ['Provide either an employee or applicant, not both.'],
            ]);
        }

        $applicant = isset($data['applicant_id']) ? Applicant::findOrFail($data['applicant_id']) : null;
        $employee = isset($data['employee_id']) ? Employee::with('applicant')->findOrFail($data['employee_id']) : null;
        $person = $applicant ?? $employee?->applicant;

        if (! $person) {
            return ApiResponse::error('That record has no personal details to create an account from.', 400);
        }

        /*
         * Checks for an account already belonging to this person.
         *
         * The employee arm is only added when there is an employee: passing a
         * null id to orWhere() makes Eloquent emit "or employee_id is null",
         * which matches every staff account and would refuse provisioning for
         * everybody.
         */
        $existing = User::query()
            ->where('applicant_id', $person->id)
            ->when($employee, fn ($q) => $q->orWhere('employee_id', $employee->id))
            ->first();

        if ($existing) {
            return ApiResponse::error(
                'A portal account already exists for this person ('.$existing->email.').',
                409
            );
        }

        $temporaryPassword = Str::password(12);

        $user = User::create([
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'last_name' => $person->last_name,
            'email' => $data['email'],
            'mobile_number' => $person->contact_number,
            'password' => Hash::make($temporaryPassword),
            'user_type' => $employee ? 'employee' : 'applicant',
            'applicant_id' => $person->id,
            'employee_id' => $employee?->id,
            'is_active' => true,
        ]);

        $user->syncRoles(['portal']);

        // Issued by the office, so replaced by the holder at the first sign-in.
        $user->forceFill(['must_change_password' => true])->save();

        $this->audit->record('create', 'users', User::class, $user->id, null, [
            'portal_access_for' => $person->full_name,
            'user_type' => $user->user_type,
        ]);

        return ApiResponse::created([
            'user' => $this->present($user->fresh('roles')),
            // Shown once. The hash cannot be reversed, so if it is lost the
            // password has to be reset rather than retrieved.
            'temporary_password' => $temporaryPassword,
            'notice' => 'Give this password to the account holder. It cannot be displayed again, and it only lets them choose their own password the first time they sign in.',
        ], 'Portal access created');
    }

    /**
     * Every client a coordinator has covered, newest first.
     */
    public function coordinatorAssignments(User $user): JsonResponse
    {
        $this->authorize('manageUsers');

        abort_unless($user->isCoordinator(), 404, 'That account is not a coordinator.');

        return ApiResponse::success(
            $user->coordinatorAssignments()
                ->with(['company', 'assigner', 'ender'])
                ->get()
                ->map(fn (CoordinatorAssignment $a) => [
                    'id' => $a->id,
                    'client_company_id' => $a->client_company_id,
                    'company_name' => $a->company?->company_name,
                    'status' => $a->status,
                    'assigned_at' => $a->assigned_at?->toIso8601String(),
                    'unassigned_at' => $a->unassigned_at?->toIso8601String(),
                    'assigned_by' => $a->assigner?->full_name,
                    'ended_by' => $a->ender?->full_name,
                    'remarks' => $a->remarks,
                ])
        );
    }

    /**
     * Assigns a coordinator to a client, or moves them to another.
     *
     * Takes effect on the coordinator's next request: their access is worked
     * out from the active assignment every time, so the old client is out of
     * reach immediately and nothing they did there is altered.
     */
    public function assignCoordinator(Request $request, User $user): JsonResponse
    {
        $this->authorize('updateUsers');

        abort_unless($user->isCoordinator(), 422, 'Only a coordinator account can be assigned to a client company.');

        $data = $request->validate([
            'client_company_id' => ['required', 'integer', 'exists:client_companies,id'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $this->assignments->assign(
            $user,
            ClientCompany::findOrFail($data['client_company_id']),
            $request->user(),
            $data['remarks'] ?? null
        );

        return ApiResponse::success(
            $this->present($user->fresh(['roles', 'activeCoordinatorAssignment.company'])),
            'Coordinator assignment updated'
        );
    }

    /**
     * The role an account of this kind may hold.
     */
    private function roleFor(string $userType, ?string $requested): string
    {
        /*
         * A portal account is an applicant or employee. It keeps the portal
         * role and nothing else: giving one a staff role would hand the HR
         * permissions to a person outside the agency.
         */
        if (in_array($userType, ['applicant', 'employee'], true)) {
            if ($requested !== null && $requested !== 'portal') {
                throw ValidationException::withMessages([
                    'role' => ['An applicant or employee account always has the portal role.'],
                ]);
            }

            return 'portal';
        }

        if ($userType === 'coordinator') {
            if ($requested !== null && $requested !== 'coordinator') {
                throw ValidationException::withMessages([
                    'role' => ['A coordinator account always has the coordinator role.'],
                ]);
            }

            return 'coordinator';
        }

        if (in_array($requested, ['coordinator', 'portal'], true)) {
            throw ValidationException::withMessages([
                'role' => ['That role belongs to a different kind of account. Create a coordinator or portal account instead.'],
            ]);
        }

        return $requested ?? $userType;
    }

    public function roles(): JsonResponse
    {
        $this->authorize('manageUsers');

        $roles = Role::withCount('users')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system_role' => (bool) $role->is_system_role,
            'users_count' => $role->users_count,
            'permissions_count' => $role->permissions()->count(),
        ]);

        // Grouped by module so a matrix of roughly fifty permissions is
        // reviewable rather than one flat column of slugs. Roles are eager
        // loaded: without it this is one query per permission, which the
        // lazy-loading guard in AppServiceProvider rightly rejects.
        $permissions = Permission::with('roles')->orderBy('module_key')->orderBy('action_key')->get()
            ->groupBy('module_key')
            ->map(fn ($group, $module) => [
                'module' => $module,
                'label' => ucwords(str_replace('_', ' ', (string) $module)),
                'permissions' => $group->map(fn (Permission $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'action' => $p->action_key,
                    'description' => $p->description,
                    'roles' => $p->roles->pluck('name'),
                ])->values(),
            ])->values();

        return ApiResponse::success(['roles' => $roles, 'permission_matrix' => $permissions]);
    }

    /**
     * Prevents the system being locked out of its own administration.
     *
     * Removing the last administrator would leave nobody able to manage users,
     * assign roles, or read the audit trail — unrecoverable without direct
     * database access.
     */
    private function assertNotLastAdministrator(User $user, ?string $newRole): void
    {
        if (! $user->hasRole('admin')) {
            return;
        }

        if ($newRole === 'admin') {
            return;
        }

        $remaining = User::role('admin')->where('is_active', true)->where('id', '!=', $user->id)->count();

        abort_if(
            $remaining === 0,
            400,
            'This is the only active administrator. Grant another user administrator access first.'
        );
    }

    /**
     * "employee" when the login reaches an employment record, directly or
     * through its applicant record; "applicant" for any other portal login.
     * The list eager-loads what this reads; a single account loads it here.
     */
    private function portalAccess(User $user): ?string
    {
        if (! $user->isPortalAccount()) {
            return null;
        }

        if ($user->employee_id) {
            return 'employee';
        }

        $user->loadMissing('applicant.employee:id,applicant_id');

        return $user->applicant?->employee ? 'employee' : 'applicant';
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'mobile_number' => $user->mobile_number,
            'user_type' => $user->user_type,
            // Which portal the login actually opens onto, by the rule above.
            'portal_access' => $this->portalAccess($user),
            'is_active' => (bool) $user->is_active,
            'roles' => $user->getRoleNames(),
            'applicant_id' => $user->applicant_id,
            'employee_id' => $user->employee_id,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'coordinator_assignment' => $user->isCoordinator() && $user->relationLoaded('activeCoordinatorAssignment')
                ? ($user->activeCoordinatorAssignment ? [
                    'client_company_id' => $user->activeCoordinatorAssignment->client_company_id,
                    'company_name' => $user->activeCoordinatorAssignment->company?->company_name,
                    'assigned_at' => $user->activeCoordinatorAssignment->assigned_at?->toIso8601String(),
                ] : null)
                : null,
        ];
    }
}
