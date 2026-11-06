<?php

namespace App\Support;

use App\Models\Applicant;
use App\Services\Matching\CompetencyScoringService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * What the digital Bio-Data asks for, and how each answer is checked.
 *
 * No official CDE Bio-Data template has been provided, so the form asks only
 * for what EMPOWER already records about an applicant - the columns of
 * `applicants` and of the education and work-history tables, with the same
 * vocabularies and limits the staff forms use. Nothing is invented: no
 * family, religion, government numbers or emergency contact. Those are listed
 * as questions for CDE in docs/07-project-management/digital-bio-data.md.
 *
 * Where each value lives:
 *
 *  - PROFILE_FIELDS are shown, not asked. Name, birth date and present
 *    address were checked against documents at the office and are locked in
 *    the portal already; contact details are edited under Profile. The form
 *    reads them from `applicants` so they are never typed twice.
 *  - DECLARED_FIELDS are the applicant's to fill in. They are held in the
 *    draft and the submitted version, and copied onto `applicants` only when
 *    HR verifies the submission - so a verified profile never changes on the
 *    applicant's say-so alone.
 *  - Education and work history are declared too, and kept with the
 *    submission. They are not written into the qualifications the matching
 *    engine scores: those are recorded by HR from documents
 *    (ApplicantQualificationController), and a submitted form must not move
 *    anybody's ranking.
 */
final class BioDataForm
{
    /** The checklist row this form submits. */
    public const REQUIREMENT_CODE = 'bio_data';

    public const PROFILE_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'suffix',
        'birth_date', 'present_address', 'contact_number', 'email',
    ];

    public const DECLARED_FIELDS = [
        'sex', 'civil_status', 'nationality', 'height_cm', 'weight_kg', 'provincial_address',
    ];

    /** Same vocabularies as the staff applicant form. */
    public const SEX = ['male', 'female'];

    public const CIVIL_STATUS = ['single', 'married', 'widowed', 'separated', 'divorced'];

    public const LABELS = [
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'suffix' => 'Suffix',
        'birth_date' => 'Date of birth',
        'present_address' => 'Present address',
        'contact_number' => 'Contact number',
        'email' => 'Email address',
        'sex' => 'Sex',
        'civil_status' => 'Civil status',
        'nationality' => 'Citizenship',
        'height_cm' => 'Height (cm)',
        'weight_kg' => 'Weight (kg)',
        'provincial_address' => 'Provincial address',
    ];

    private const MAX_EDUCATIONS = 10;

    private const MAX_EXPERIENCES = 20;

    /**
     * The rules for a draft or for a submission.
     *
     * A draft accepts anything left blank but nothing malformed: a height of
     * 1,000 cm or a job that ends before it starts is refused even mid-way,
     * so the applicant hears about it next to the field rather than at the
     * end. Submission adds what must be filled in.
     */
    public static function rules(bool $forSubmission, ?Applicant $applicant = null): array
    {
        $need = $forSubmission ? 'required' : 'nullable';
        $today = now()->toDateString();
        $born = $applicant?->birth_date?->toDateString();
        $bornYear = $applicant?->birth_date?->year;

        return [
            'sex' => [$need, Rule::in(self::SEX)],
            'civil_status' => [$need, Rule::in(self::CIVIL_STATUS)],
            'nationality' => [$need, 'string', 'max:80'],
            'height_cm' => ['nullable', 'numeric', 'decimal:0,2', 'between:100,250'],
            'weight_kg' => ['nullable', 'numeric', 'decimal:0,2', 'between:30,300'],
            'provincial_address' => ['nullable', 'string', 'max:255'],

            'educations' => [$forSubmission ? 'required' : 'present', 'array', $forSubmission ? 'min:1' : 'min:0', 'max:'.self::MAX_EDUCATIONS],
            'educations.*.education_level' => [$need, Rule::in(CompetencyScoringService::acceptedValues('education'))],
            'educations.*.school_name' => [$need, 'string', 'max:190'],
            'educations.*.course_program' => ['nullable', 'string', 'max:190'],
            'educations.*.graduation_year' => array_filter([
                'nullable', 'integer', 'between:1950,'.(now()->year + 6),
                $bornYear ? 'gt:'.$bornYear : null,
            ]),
            'educations.*.honors' => ['nullable', 'string', 'max:120'],

            // Work history may be empty: a first job is common at CDE.
            'experiences' => ['present', 'array', 'max:'.self::MAX_EXPERIENCES],
            'experiences.*.company_name' => [$need, 'string', 'max:190'],
            'experiences.*.position_title' => [$need, 'string', 'max:150'],
            'experiences.*.start_date' => array_filter([
                $need, 'date_format:Y-m-d', 'before_or_equal:'.$today,
                $born ? 'after:'.$born : null,
            ]),
            // Blank means the applicant still works there.
            'experiences.*.end_date' => [
                'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:experiences.*.start_date',
            ],
            'experiences.*.responsibilities' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'sex.required' => 'Choose your sex.',
            'civil_status.required' => 'Choose your civil status.',
            'nationality.required' => 'Enter your citizenship.',
            'height_cm.between' => 'Enter your height in centimetres, between 100 and 250.',
            'weight_kg.between' => 'Enter your weight in kilograms, between 30 and 300.',
            'educations.required' => 'Add at least one school you attended.',
            'educations.min' => 'Add at least one school you attended.',
            'educations.max' => 'You can list up to '.self::MAX_EDUCATIONS.' schools.',
            'educations.*.education_level.required' => 'Choose the level for school :position.',
            'educations.*.school_name.required' => 'Enter the name of school :position.',
            'educations.*.graduation_year.between' => 'Enter a real year for school :position.',
            'educations.*.graduation_year.gt' => 'The year for school :position is before you were born.',
            'experiences.max' => 'You can list up to '.self::MAX_EXPERIENCES.' jobs.',
            'experiences.*.company_name.required' => 'Enter the employer for job :position.',
            'experiences.*.position_title.required' => 'Enter your position for job :position.',
            'experiences.*.start_date.required' => 'Enter when job :position started.',
            'experiences.*.start_date.date_format' => 'Enter the start of job :position as a full date.',
            'experiences.*.start_date.before_or_equal' => 'Job :position cannot start in the future.',
            'experiences.*.start_date.after' => 'Job :position cannot start before you were born.',
            'experiences.*.end_date.date_format' => 'Enter the end of job :position as a full date.',
            'experiences.*.end_date.before_or_equal' => 'Job :position cannot end in the future. Leave it blank if you still work there.',
            'experiences.*.end_date.after_or_equal' => 'Job :position cannot end before it started.',
        ];
    }

    /** Validates and returns the form in its stored shape. */
    public static function validate(array $input, bool $forSubmission, ?Applicant $applicant = null): array
    {
        $input = self::withoutEmptyRows($input);

        Validator::make($input, self::rules($forSubmission, $applicant), self::messages())->validate();

        return self::normalise($input);
    }

    /**
     * What still stops the form being submitted, field by field, worded for
     * the applicant. Empty when it is ready.
     *
     * @return array<string, string>
     */
    public static function missing(array $form, ?Applicant $applicant = null): array
    {
        $validator = Validator::make(self::withoutEmptyRows($form), self::rules(true, $applicant), self::messages());

        return collect($validator->errors()->messages())->map(fn (array $m) => $m[0])->all();
    }

    /**
     * The stored shape: every key present, text trimmed, blanks as null, and
     * nothing that is not on the form. Unknown keys sent by a client are
     * dropped here rather than stored.
     */
    public static function normalise(array $input): array
    {
        $text = fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : (is_numeric($value) ? $value : null);
        $number = fn ($value) => $value === null || $value === '' ? null : round((float) $value, 2);

        return [
            'sex' => $text($input['sex'] ?? null),
            'civil_status' => $text($input['civil_status'] ?? null),
            'nationality' => $text($input['nationality'] ?? null),
            'height_cm' => $number($input['height_cm'] ?? null),
            'weight_kg' => $number($input['weight_kg'] ?? null),
            'provincial_address' => $text($input['provincial_address'] ?? null),
            'educations' => collect($input['educations'] ?? [])->map(fn ($row) => [
                'education_level' => $text($row['education_level'] ?? null),
                'school_name' => $text($row['school_name'] ?? null),
                'course_program' => $text($row['course_program'] ?? null),
                'graduation_year' => filled($row['graduation_year'] ?? null) ? (int) $row['graduation_year'] : null,
                'honors' => $text($row['honors'] ?? null),
            ])->values()->all(),
            'experiences' => collect($input['experiences'] ?? [])->map(fn ($row) => [
                'company_name' => $text($row['company_name'] ?? null),
                'position_title' => $text($row['position_title'] ?? null),
                'start_date' => $text($row['start_date'] ?? null),
                'end_date' => $text($row['end_date'] ?? null),
                'responsibilities' => $text($row['responsibilities'] ?? null),
            ])->values()->all(),
        ];
    }

    /**
     * A first form, filled from what the office already has on record, so
     * the applicant reviews and completes it instead of typing it again.
     */
    public static function fromProfile(Applicant $applicant): array
    {
        $applicant->loadMissing(['educations', 'experiences']);

        return self::normalise([
            ...$applicant->only(self::DECLARED_FIELDS),
            'educations' => $applicant->educations->map(fn ($e) => $e->only([
                'education_level', 'school_name', 'course_program', 'graduation_year', 'honors',
            ]))->all(),
            'experiences' => $applicant->experiences->map(fn ($e) => [
                'company_name' => $e->company_name,
                'position_title' => $e->position_title,
                'start_date' => $e->start_date?->toDateString(),
                'end_date' => $e->end_date?->toDateString(),
                'responsibilities' => $e->responsibilities,
            ])->all(),
        ]);
    }

    /** The details read from the profile rather than asked. */
    public static function profileValues(Applicant $applicant): array
    {
        return [
            'applicant_code' => $applicant->applicant_code,
            'full_name' => $applicant->full_name,
            ...collect(self::PROFILE_FIELDS)->mapWithKeys(fn ($field) => [
                $field => $field === 'birth_date'
                    ? $applicant->birth_date?->toDateString()
                    : $applicant->{$field},
            ])->all(),
            'age' => $applicant->age,
        ];
    }

    /** One submission: the profile as it stood, and the form. */
    public static function snapshot(Applicant $applicant, array $form): array
    {
        return [
            'profile' => self::profileValues($applicant),
            'form' => self::normalise($form),
        ];
    }

    /**
     * Declared values that differ from the profile, as [field => [profile, declared]].
     * Blank declarations never count: a field left empty does not ask for
     * anything on file to be cleared.
     */
    public static function differencesFromProfile(Applicant $applicant, array $form): array
    {
        $changes = [];

        foreach (self::DECLARED_FIELDS as $field) {
            $declared = $form[$field] ?? null;
            $current = $applicant->{$field};

            if ($declared === null) {
                continue;
            }

            $same = is_numeric($declared) && is_numeric($current)
                ? round((float) $declared, 2) === round((float) $current, 2)
                : mb_strtolower(trim((string) $current)) === mb_strtolower(trim((string) $declared));

            if (! $same) {
                $changes[$field] = [$current === null ? null : (is_numeric($declared) ? (float) $current : $current), $declared];
            }
        }

        return $changes;
    }

    /** Value/label lists for the form's pickers. */
    public static function options(): array
    {
        $labelled = fn (array $values) => array_map(
            fn ($value) => ['value' => $value, 'label' => ucwords(str_replace('_', ' ', $value))],
            $values
        );

        return [
            'sex' => $labelled(self::SEX),
            'civil_status' => $labelled(self::CIVIL_STATUS),
            'education_level' => $labelled(CompetencyScoringService::acceptedValues('education')),
        ];
    }

    public static function optionLabel(string $list, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return collect(self::options()[$list] ?? [])->firstWhere('value', $value)['label'] ?? $value;
    }

    /** A row the applicant added and left completely blank is not an entry. */
    private static function withoutEmptyRows(array $input): array
    {
        foreach (['educations', 'experiences'] as $list) {
            if (isset($input[$list]) && is_array($input[$list])) {
                $input[$list] = array_values(array_filter(
                    $input[$list],
                    fn ($row) => is_array($row) && collect(Arr::flatten($row))->contains(fn ($v) => filled($v))
                ));
            }
        }

        return $input;
    }
}
