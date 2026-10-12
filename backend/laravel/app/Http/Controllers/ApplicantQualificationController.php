<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApplicantResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicantCertification;
use App\Models\ApplicantEducation;
use App\Models\ApplicantExperience;
use App\Models\ApplicantSkill;
use App\Models\CompanyCriteria;
use App\Models\CriteriaCatalog;
use App\Models\RequestCriteria;
use App\Services\AuditService;
use App\Services\Matching\CompetencyScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What an applicant brings: education, work history, skills, certificates.
 *
 * These are what the matching engine compares with a request's criteria, and
 * until now nothing could record them - the tables were filled only by the demo
 * seeder. In real use every applicant scored zero on education, experience,
 * skills, and certifications, whatever their resume said, and the ranking was
 * decided by the few details that could be entered. HR records them here from
 * the resume and documents the applicant submitted.
 *
 * Staff only. An applicant's own qualifications feed their ranking, so they
 * are taken from the documents by an officer rather than self-declared.
 */
class ApplicantQualificationController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Replaces the applicant's qualifications as one set.
     *
     * Sent whole rather than item by item, because the screen shows the whole
     * profile and saves it once: a half-applied edit would leave a ranking
     * computed from a profile nobody actually entered.
     */
    public function update(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('update', $applicant);

        $data = $request->validate([
            'educations' => ['present', 'array', 'max:10'],
            'educations.*.education_level' => ['required', Rule::in(CompetencyScoringService::acceptedValues('education'))],
            'educations.*.school_name' => ['required', 'string', 'max:190'],
            'educations.*.course_program' => ['nullable', 'string', 'max:190'],
            'educations.*.graduation_year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 6)],

            'experiences' => ['present', 'array', 'max:20'],
            'experiences.*.company_name' => ['required', 'string', 'max:190'],
            'experiences.*.position_title' => ['required', 'string', 'max:150'],
            'experiences.*.months_experience' => ['required', 'integer', 'between:0,600'],

            'skills' => ['present', 'array', 'max:40'],
            'skills.*' => ['required', 'string', 'max:120', 'distinct:ignore_case'],

            'certifications' => ['present', 'array', 'max:20'],
            'certifications.*.certification_name' => ['required', 'string', 'max:150'],
            'certifications.*.issuer' => ['nullable', 'string', 'max:150'],
            'certifications.*.issued_at' => ['nullable', 'date'],
            'certifications.*.expires_at' => ['nullable', 'date'],
        ], [
            'skills.*.distinct' => 'This skill is listed twice.',
        ]);

        $before = $this->summary($applicant);

        DB::transaction(function () use ($applicant, $data) {
            $applicant->educations()->delete();
            $applicant->experiences()->delete();
            $applicant->skills()->delete();
            $applicant->certifications()->delete();

            foreach ($data['educations'] as $row) {
                ApplicantEducation::create($row + ['applicant_id' => $applicant->id]);
            }

            foreach ($data['experiences'] as $row) {
                ApplicantExperience::create($row + ['applicant_id' => $applicant->id]);
            }

            foreach ($data['skills'] as $skill) {
                ApplicantSkill::create(['applicant_id' => $applicant->id, 'skill_name' => trim($skill)]);
            }

            foreach ($data['certifications'] as $row) {
                ApplicantCertification::create($row + ['applicant_id' => $applicant->id]);
            }

            $applicant->forceFill(['profile_updated_at' => now()])->save();
        });

        $applicant->unsetRelations();

        $this->audit->recordUpdate(
            'applicants',
            Applicant::class,
            $applicant->id,
            $before,
            $this->summary($applicant)
        );

        return ApiResponse::success(
            new ApplicantResource($applicant->load(['educations', 'experiences', 'skills', 'certifications'])),
            'Qualifications saved. Rankings that include this applicant should be run again.'
        );
    }

    /**
     * The words already in use for skills and certificates, on both sides.
     *
     * The engine matches a requirement to a qualification by name, ignoring
     * case and spacing but not wording: "Computer Literacy" and "Computer
     * Literate" are different. Offering the names already used - by applicants
     * and in the criteria clients have set - is what keeps the two vocabularies
     * the same without anybody having to police them.
     */
    public function vocabulary(): JsonResponse
    {
        $this->authorize('viewAny', Applicant::class);

        $fromCriteria = function (string $code) {
            $id = CriteriaCatalog::where('criteria_code', $code)->value('id');

            if (! $id) {
                return collect();
            }

            return RequestCriteria::where('criteria_id', $id)->pluck('expected_value')
                ->concat(CompanyCriteria::where('criteria_id', $id)->pluck('expected_value'))
                ->filter()
                ->flatMap(fn ($v) => explode(',', $v));
        };

        $unique = fn ($values) => collect($values)
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique(fn ($v) => mb_strtolower(preg_replace('/\s+/', ' ', $v)))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return ApiResponse::success([
            'skills' => $unique(ApplicantSkill::query()->distinct()->pluck('skill_name')->concat($fromCriteria('skills'))),
            'certifications' => $unique(
                ApplicantCertification::query()->distinct()->pluck('certification_name')->concat($fromCriteria('certifications'))
            ),
            'education_levels' => collect(CompetencyScoringService::acceptedValues('education'))
                ->map(fn ($level) => ['value' => $level, 'label' => ucwords(str_replace('_', ' ', $level))])
                ->values(),
        ]);
    }

    /**
     * A compact before/after for the audit trail.
     */
    private function summary(Applicant $applicant): array
    {
        return [
            'education' => $applicant->educations()->pluck('education_level')->implode(', '),
            'experience_months' => (int) $applicant->experiences()->sum('months_experience'),
            'skills' => $applicant->skills()->pluck('skill_name')->implode(', '),
            'certifications' => $applicant->certifications()->pluck('certification_name')->implode(', '),
        ];
    }
}
