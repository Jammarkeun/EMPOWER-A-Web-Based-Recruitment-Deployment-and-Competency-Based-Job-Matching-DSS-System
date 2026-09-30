<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\JobPosition;
use App\Models\RequirementType;
use App\Models\User;
use App\Notifications\ApplicantSelfRegistered;
use App\Rules\PasswordPolicy;
use App\Services\AuditService;
use App\Services\ReferenceCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Applicant self-registration.
 *
 * The agency requires every applicant to visit the office in person to submit
 * documents, so this does not replace that step and is not meant to. What it
 * removes is the phone call beforehand: someone can register from home, see
 * exactly which documents to bring, and arrive prepared.
 *
 * A record created here is explicitly not the same as one an HR officer typed in
 * at the counter. Nobody has seen this person or checked an ID, so the record is
 * held before screening until an officer confirms their identity. Until then
 * they cannot be screened, evaluated, or deployed.
 */
class RegistrationController extends Controller
{
    public function __construct(
        private readonly ReferenceCodeService $codes,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * What a prospective applicant needs to know before signing up.
     *
     * Public, so the registration page can show the document checklist without
     * requiring an account first — which is most of the value of the page.
     */
    public function requirements(): JsonResponse
    {
        return ApiResponse::success([
            'primary' => RequirementType::active()->primary()->orderBy('display_order')
                ->get(['requirement_name', 'is_required']),
            'final' => RequirementType::active()->final()->orderBy('display_order')
                ->get(['requirement_name', 'is_required']),
            'office' => [
                'name' => config('empower.organisation.name'),
                'address' => config('empower.organisation.address'),
                'contact' => config('empower.organisation.contact'),
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'sex' => ['nullable', Rule::in(['male', 'female'])],
            // Below 15 is under the minimum working age set by RA 9231, so the
            // record should never be created at all.
            'birth_date' => ['required', 'date', 'before:'.now()->subYears(15)->toDateString()],
            'contact_number' => ['required', 'string', 'max:40'],
            'present_address' => ['required', 'string', 'max:255'],

            /*
             * The position is chosen from the list, not typed.
             *
             * Free text produced applications for jobs the agency does not
             * place and three spellings of the same role, none of which could
             * be matched against a client's request. Storing the reference
             * rather than the words also means renaming a position later does
             * not orphan everyone who applied for it.
             *
             * Required only while there is something to choose. An agency
             * between contracts still accepts applicants, and a form that
             * cannot be submitted because no client is currently hiring would
             * turn away the people the pool exists to hold.
             */
            'preferred_position_id' => [
                Rule::requiredIf(fn () => JobPosition::openForApplication()->exists()),
                'nullable',
                'integer',
                Rule::exists('job_positions', 'id')->whereNull('deleted_at'),
            ],

            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy()],
        ], [
            'birth_date.before' => 'You must be at least 15 years old to apply.',
            'email.unique' => 'An account already exists for this email address. Try signing in instead.',
            'preferred_position_id.required' => 'Please choose the position you are applying for.',
            'preferred_position_id.exists' => 'That position is no longer available. Please choose another.',
        ]);

        /*
         * Checked separately from `exists` so the message can say what actually
         * happened. A position withdrawn between the page loading and the form
         * being submitted is a real race - vacancies close - and "no longer
         * available, please choose another" is a far better answer than a
         * validation error implying the applicant did something wrong.
         */
        $position = null;

        if (! empty($data['preferred_position_id'])) {
            $position = JobPosition::openForApplication()
                ->find($data['preferred_position_id']);

            if (! $position) {
                return ApiResponse::error(
                    'That position is no longer being offered. Please go back and choose another.',
                    422
                );
            }
        }

        /*
         * Reject an obvious re-registration.
         *
         * People reapply, and an agency ends up with the same person under two
         * records with different histories. Name and date of birth together are
         * a strong enough signal to stop it here, and HR can merge or reopen the
         * existing record instead.
         */
        $existing = Applicant::query()
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($data['first_name'])])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($data['last_name'])])
            ->whereDate('birth_date', $data['birth_date'])
            ->first();

        if ($existing) {
            return ApiResponse::error(
                'Our records already show an application under this name and date of birth. '
                .'Please contact the office rather than registering again.',
                409
            );
        }

        [$applicant, $user, $token] = DB::transaction(function () use ($data, $position) {
            $applicant = Applicant::create([
                'applicant_code' => $this->codes->applicant(),
                'source_channel' => 'online',
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'sex' => $data['sex'] ?? null,
                'birth_date' => $data['birth_date'],
                'contact_number' => $data['contact_number'],
                'email' => $data['email'],
                'present_address' => $data['present_address'],
                'preferred_position_id' => $position?->id,
                // The title as well as the reference. The reference is what the
                // system matches on; this is the wording the applicant was
                // shown, and a record of what they were told they applied for
                // should not silently change if the position is renamed.
                'preferred_position' => $position?->position_title,
                'application_date' => now()->toDateString(),
                // Nobody created this on the applicant's behalf, so there is no
                // staff member to attribute it to.
                'created_by' => null,
            ]);

            // Status and the self-registration marks are guarded, so they are set
            // directly rather than through mass assignment.
            $applicant->forceFill([
                'current_status' => 'applied',
                'self_registered_at' => now(),
            ])->save();

            // The checklist is created now, not at the office. Seeing exactly
            // which documents to bring is most of the reason to register online
            // at all, and it is what saves the wasted first trip.
            $checklist = RequirementType::active()->get()->map(fn (RequirementType $type) => [
                'applicant_id' => $applicant->id,
                'requirement_type_id' => $type->id,
                'status' => 'missing',
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if ($checklist !== []) {
                DB::table('applicant_requirements')->insert($checklist);
            }

            $user = User::create([
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'mobile_number' => $data['contact_number'],
                'password' => Hash::make($data['password']),
                'user_type' => 'applicant',
                'applicant_id' => $applicant->id,
                'is_active' => true,
            ]);

            // Portal accounts hold no staff permissions at all.
            $user->syncRoles(['portal']);

            $token = $user->createToken('empower-portal')->plainTextToken;

            return [$applicant, $user, $token];
        });

        $this->audit->record(
            action: 'create',
            module: 'applicants',
            recordType: Applicant::class,
            recordId: $applicant->id,
            newValues: [
                'self_registered' => true,
                'applicant_code' => $applicant->applicant_code,
                'applied_for' => $position?->position_title,
                'awaiting_identity_check' => true,
            ],
        );

        // Tell the office someone is coming. Sent outside the transaction so a
        // notification failure cannot roll back an account the applicant has
        // already been told was created.
        User::query()
            ->whereIn('user_type', ['admin', 'hr'])
            ->where('is_active', true)
            ->get()
            ->each->notify(new ApplicantSelfRegistered($applicant));

        return ApiResponse::created([
            'token' => $token,
            'token_type' => 'Bearer',
            'applicant_code' => $applicant->applicant_code,
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'user_type' => $user->user_type,
                'roles' => $user->getRoleNames(),
                'permissions' => [],
            ],
            'next_step' => 'Visit the CDE Manpower Services office with your documents to complete '
                .'your application. Your reference number is '.$applicant->applicant_code.'.',
        ], 'Account created');
    }

    /**
     * Lets the sign-up form warn about a taken email before submission.
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        return ApiResponse::success([
            'available' => ! User::where('email', $data['email'])->exists(),
        ]);
    }
}
