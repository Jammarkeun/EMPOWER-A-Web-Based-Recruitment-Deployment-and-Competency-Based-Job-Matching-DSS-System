<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manageUsers');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'user_type' => ['nullable', Rule::in(['admin', 'hr', 'applicant', 'employee'])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] ?? null, function ($q, $v) {
                $operator = $q->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(fn ($sub) => $sub
                    ->where('first_name', $operator, "%{$v}%")
                    ->orWhere('last_name', $operator, "%{$v}%")
                    ->orWhere('email', $operator, "%{$v}%"));
            })
            ->when($filters['user_type'] ?? null, fn ($q, $v) => $q->where('user_type', $v))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('user_type')
            ->orderBy('last_name')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(
            $users->through(fn (User $user) => $this->present($user)),
            'Users retrieved'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('manageUsers');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'user_type' => ['required', Rule::in(['admin', 'hr'])],
            'role' => ['required', 'string', 'exists:roles,name'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        // Staff accounts only. Portal logins are created from the applicant or
        // employee record through provisionPortalAccess, so an account can never
        // be linked to the wrong person by typing an ID by hand.
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
        // ever claim an address is verified.
        $user->forceFill(['email_verified_at' => now()])->save();

        $user->syncRoles([$data['role']]);

        $this->audit->record('create', 'users', User::class, $user->id, null, [
            'email' => $data['email'],
            'user_type' => $data['user_type'],
            'role' => $data['role'],
        ]);

        return ApiResponse::created($this->present($user->fresh('roles')), 'User created');
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('manageUsers');

        return ApiResponse::success($this->present($user->load('roles', 'applicant', 'employee')));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('manageUsers');

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
        ]);

        $before = $user->only(['first_name', 'last_name', 'email', 'mobile_number']);

        $user->update(collect($data)->except('role')->all());

        if (isset($data['role'])) {
            $this->assertNotLastAdministrator($user, $data['role']);
            $user->syncRoles([$data['role']]);
        }

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
        $this->authorize('manageUsers');

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
        $this->authorize('manageUsers');

        $data = $request->validate([
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        // Every existing session is ended: a password reset that leaves old
        // sessions alive does not actually lock anyone out.
        $user->tokens()->delete();

        $this->audit->record('update', 'users', User::class, $user->id, null, [
            'password' => '[redacted]',
            'sessions_revoked' => true,
        ]);

        return ApiResponse::success(null, 'Password reset. The user has been signed out everywhere.');
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
        $this->authorize('manageUsers');

        $data = $request->validate([
            'applicant_id' => ['required_without:employee_id', 'nullable', 'integer', 'exists:applicants,id'],
            'employee_id' => ['required_without:applicant_id', 'nullable', 'integer', 'exists:employees,id'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
        ]);

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

        $this->audit->record('create', 'users', User::class, $user->id, null, [
            'portal_access_for' => $person->full_name,
            'user_type' => $user->user_type,
        ]);

        return ApiResponse::created([
            'user' => $this->present($user->fresh('roles')),
            // Shown once. The hash cannot be reversed, so if it is lost the
            // password has to be reset rather than retrieved.
            'temporary_password' => $temporaryPassword,
            'notice' => 'Give this password to the account holder. It cannot be displayed again.',
        ], 'Portal access created');
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
            'is_active' => (bool) $user->is_active,
            'roles' => $user->getRoleNames(),
            'applicant_id' => $user->applicant_id,
            'employee_id' => $user->employee_id,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
