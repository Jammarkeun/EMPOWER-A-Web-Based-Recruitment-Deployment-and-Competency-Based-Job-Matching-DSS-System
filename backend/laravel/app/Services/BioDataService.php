<?php

namespace App\Services;

use App\Http\Resources\ApplicantRequirementResource;
use App\Models\Applicant;
use App\Models\ApplicantBioData;
use App\Models\ApplicantBioDataVersion;
use App\Models\ApplicantRequirement;
use App\Models\ApplicantRequirementHistory;
use App\Models\RequirementType;
use App\Models\User;
use App\Notifications\BioDataReviewed;
use App\Notifications\BioDataSubmitted;
use App\Support\BioDataForm;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * The digital Bio-Data, from draft to HR's decision.
 *
 * It is one requirement - the applicant's `bio_data` checklist row - and the
 * form is a way of submitting it, like an upload or the office visit. So the
 * row's status stays the single answer to "is the Bio-Data done?": a draft
 * leaves it alone, a submission makes it `submitted`, and only HR makes it
 * `verified`. Folder categorisation, the review inbox and the dashboard read
 * that row exactly as they did before.
 *
 * States, as both sides see them:
 *   not_started       nothing sent, no draft
 *   draft             saved, not sent
 *   submitted         sent (form or upload), nobody has opened it
 *   under_review      an officer has opened it
 *   verified          HR accepted it, however it was sent
 *   needs_correction  HR sent it back (or rejected an upload)
 */
class BioDataService
{
    public function __construct(
        private readonly FolderCategoryService $folders,
        private readonly ApplicantLifecycleService $lifecycle,
        private readonly AuditService $audit,
    ) {
    }

    public function requirementType(): ?RequirementType
    {
        return RequirementType::where('requirement_code', BioDataForm::REQUIREMENT_CODE)->first();
    }

    public function requirement(Applicant $applicant): ?ApplicantRequirement
    {
        $type = $this->requirementType();

        return $type
            ? ApplicantRequirement::where('applicant_id', $applicant->id)->where('requirement_type_id', $type->id)->first()
            : null;
    }

    public static function isBioData(?RequirementType $type): bool
    {
        return $type?->requirement_code === BioDataForm::REQUIREMENT_CODE;
    }

    // ------------------------------------------------------------------ state

    /**
     * The form's state. It follows the form, not only the checklist row: a
     * Bio-Data verified some other way (a paper copy at the counter, or an
     * upload from before the form existed) keeps the requirement complete,
     * but the form itself is still to be filled in.
     */
    public function state(?ApplicantRequirement $requirement, ?ApplicantBioDataVersion $latest, ?ApplicantBioData $draft): string
    {
        return match (true) {
            (bool) $latest?->isPending() => $requirement?->first_viewed_at ? 'under_review' : 'submitted',
            self::verifiedByForm($requirement) => 'verified',
            $latest?->review_outcome === ApplicantBioDataVersion::NEEDS_CORRECTION,
            in_array($requirement?->status, ['needs_correction', 'rejected'], true) => 'needs_correction',
            $draft?->draft !== null => 'draft',
            default => 'not_started',
        };
    }

    /** The requirement is complete because HR verified the form itself. */
    public static function verifiedByForm(?ApplicantRequirement $requirement): bool
    {
        return $requirement?->status === 'verified' && $requirement->verification_method === 'online_form';
    }

    /** Complete through an earlier paper copy or upload, not the form. */
    public static function verifiedEarlier(?ApplicantRequirement $requirement): bool
    {
        return $requirement?->status === 'verified' && ! self::verifiedByForm($requirement);
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'under_review' => 'Under review',
            'verified' => 'Verified',
            'needs_correction' => 'Requires correction',
            default => 'Not started',
        };
    }

    /**
     * Why the applicant cannot change the form now, or null when they can.
     *
     * Closed while HR has it (a submitted form must be the one HR reads) and
     * once HR has verified the form (a change then goes through HR, as with
     * any accepted document). A Bio-Data verified earlier on paper or as an
     * upload does not close it: every account is asked for the form.
     */
    public function lockReason(?ApplicantRequirement $requirement, ?ApplicantBioDataVersion $latest): ?string
    {
        if ($latest?->isPending()) {
            return 'Your Bio-Data has been sent and is waiting for the office. You can change it again if they return it for correction.';
        }

        if (self::verifiedByForm($requirement)) {
            return 'Your Bio-Data has been accepted by the office. Contact HR if anything in it needs changing.';
        }

        return null;
    }

    /** The values the form opens with: the draft, else the last submission, else the profile. */
    public function currentForm(Applicant $applicant): array
    {
        $draft = $applicant->bioData?->draft;

        if ($draft !== null) {
            return BioDataForm::normalise($draft);
        }

        $latest = $applicant->latestBioDataVersion;

        return $latest
            ? BioDataForm::normalise($latest->data['form'] ?? [])
            : BioDataForm::fromProfile($applicant);
    }

    // --------------------------------------------------------- applicant side

    /**
     * Saves the applicant's working copy. Changes nothing else: not the
     * checklist, not the profile, and nothing HR sees.
     */
    public function saveDraft(Applicant $applicant, array $input): ApplicantBioData
    {
        $this->assertEditable($applicant);

        $form = BioDataForm::validate($input, false, $applicant);

        return ApplicantBioData::updateOrCreate(
            ['applicant_id' => $applicant->id],
            ['draft' => $form, 'draft_saved_at' => now()]
        );
    }

    /**
     * Sends the saved form to the office.
     *
     * What is sent is what was last saved - the same values the review
     * screen showed - checked against the submission rules. It becomes a new
     * version and HR is told. The checklist row becomes `submitted` (never
     * `verified`) - unless the Bio-Data was already verified another way, in
     * which case it stays complete, and nobody changes folder, while HR
     * reviews the form.
     */
    public function submit(Applicant $applicant, User $actor): ApplicantBioDataVersion
    {
        $type = $this->requirementType();
        abort_if(! $type || ! $type->active_flag, 404, 'Bio-Data is not currently being collected.');

        $version = DB::transaction(function () use ($applicant, $actor, $type) {
            $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
                ->where('requirement_type_id', $type->id)
                ->lockForUpdate()
                ->first()
                ?? new ApplicantRequirement(['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id]);

            $applicant->unsetRelation('latestBioDataVersion')->unsetRelation('bioData');
            $this->assertEditable($applicant, $requirement);

            $form = BioDataForm::validate($this->currentForm($applicant), true, $applicant);

            $version = ApplicantBioDataVersion::create([
                'applicant_id' => $applicant->id,
                'version_number' => (int) ApplicantBioDataVersion::where('applicant_id', $applicant->id)->max('version_number') + 1,
                'data' => BioDataForm::snapshot($applicant, $form),
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
            ]);

            $requirement->fill([
                'submitted_at' => now(),
                'submitted_via' => ApplicantRequirement::VIA_DIGITAL_FORM,
                // A new submission is a new thing to review, as with a
                // replacement upload.
                'first_viewed_at' => null,
                'first_viewed_by' => null,
            ] + (self::verifiedEarlier($requirement) ? [] : [
                'status' => 'submitted',
                'verified_at' => null,
                'verified_by' => null,
                'verification_method' => null,
                'verification_note' => null,
                'rejection_reason' => null,
            ]))->save();

            ApplicantBioData::where('applicant_id', $applicant->id)->update(['draft' => null, 'draft_saved_at' => null]);

            $applicant->unsetRelation('requirements');
            $this->folders->recalculate($applicant);

            return $version;
        });

        // Which version and whose, not the details themselves.
        $this->audit->record(
            action: 'create',
            module: 'requirements',
            recordType: ApplicantBioDataVersion::class,
            recordId: $version->id,
            newValues: [
                'requirement' => $type->requirement_name,
                'submitted_by' => 'applicant',
                'submitted_via' => 'digital form',
                'version' => $version->version_number,
                'applicant_code' => $applicant->applicant_code,
            ],
        );

        User::query()
            ->whereIn('user_type', ['admin', 'hr'])
            ->where('is_active', true)
            ->get()
            ->each->notify(new BioDataSubmitted($applicant, $version));

        return $version;
    }

    // ------------------------------------------------------------- staff side

    /**
     * An officer opened the submitted form: from now on it is under review,
     * exactly as opening an uploaded file puts that under review. Audited
     * every time, since it is personal information being read.
     */
    public function recordStaffView(Applicant $applicant, User $staff): void
    {
        $requirement = $this->requirement($applicant);
        $latest = $applicant->latestBioDataVersion;

        $entered = $requirement
            && $requirement->isDigitalSubmission()
            && $latest?->isPending()
            && $staff->isStaff()
            && $requirement->markFirstViewedBy($staff);

        $this->audit->record(
            action: 'view',
            module: 'requirements',
            recordType: ApplicantBioDataVersion::class,
            recordId: $latest?->id,
            newValues: [
                'document' => 'Digital Bio-Data'.($latest ? ' v'.$latest->version_number : ''),
                'applicant_id' => $applicant->id,
                'entered_review' => (bool) $entered,
            ],
        );
    }

    /**
     * HR's decision on the submission waiting for review.
     *
     * Verifying marks the checklist row verified - the folder is recalculated
     * from it like any other document - and writes the declared details that
     * differ onto the profile, recording each change. Returning it needs a
     * note saying what to correct; the row becomes `needs_correction` and the
     * applicant can edit and send it again as a new version.
     */
    public function review(Applicant $applicant, User $actor, string $outcome, ?string $note): ApplicantRequirement
    {
        $type = $this->requirementType();

        [$version, $before, $profileChanges, $requirement] = DB::transaction(function () use ($applicant, $actor, $outcome, $note, $type) {
            $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
                ->where('requirement_type_id', $type?->id)
                ->lockForUpdate()
                ->first();

            $version = ApplicantBioDataVersion::where('applicant_id', $applicant->id)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            abort_unless(
                $requirement && $version?->isPending(),
                409,
                'There is no submitted Bio-Data waiting for review.'
            );

            $before = $requirement->only(['status', 'rejection_reason', 'verification_method']);
            $verified = $outcome === ApplicantBioDataVersion::VERIFIED;
            $earlier = self::verifiedEarlier($requirement);

            if ($verified && $earlier) {
                // The paper copy or upload it was verified on stays on record.
                ApplicantRequirementHistory::create([
                    'applicant_requirement_id' => $requirement->id,
                    'applicant_id' => $requirement->applicant_id,
                    'requirement_type_id' => $requirement->requirement_type_id,
                    'status' => $requirement->status,
                    'verified_at' => $requirement->verified_at,
                    'verified_by' => $requirement->verified_by,
                    'verification_method' => $requirement->verification_method,
                    'reason' => 'replaced_by_digital_form',
                    'replaced_at' => now(),
                    'replaced_by' => $actor->id,
                ]);
            }

            // Returned while an earlier verification stands, the requirement
            // stays complete on that one - nobody changes folder - and the
            // note travels with the version.
            if ($verified || ! $earlier) {
                $requirement->fill([
                    'status' => $verified ? 'verified' : 'needs_correction',
                    // Shown beside the requirement in the portal, as an
                    // upload's rejection reason is.
                    'rejection_reason' => $verified ? null : $note,
                    'verified_at' => $verified ? now() : null,
                    'verified_by' => $verified ? $actor->id : null,
                    'verification_method' => $verified ? 'online_form' : null,
                    'verification_note' => $verified ? $note : null,
                ])->save();
            }

            $profileChanges = $this->closeVersion($applicant, $version, $verified ? 'verified' : 'needs_correction', $actor, $note);

            $applicant->unsetRelation('requirements');
            $this->folders->recalculate($applicant);

            return [$version, $before, $profileChanges, $requirement];
        });

        $this->recordReview($applicant, $version, $before, $profileChanges);

        $applicant->unsetRelation('requirements');
        $this->lifecycle->syncStatusToDocuments($applicant->fresh(), $actor);

        return $requirement;
    }

    /**
     * Keeps the version in step when HR decides through the checklist itself
     * (the row's Verify button, bulk verify, a status change) rather than
     * through the Bio-Data review. Called inside that change's transaction.
     * Without it a form verified from the checklist would stay "waiting" in
     * its own history, and its details would never reach the profile.
     *
     * @return array<string, array{0: mixed, 1: mixed}> profile changes written
     */
    public function syncFromChecklist(ApplicantRequirement $requirement, User $actor): array
    {
        if (! $requirement->isDigitalSubmission()) {
            return [];
        }

        $outcome = match ($requirement->status) {
            'verified' => ApplicantBioDataVersion::VERIFIED,
            'needs_correction', 'rejected' => ApplicantBioDataVersion::NEEDS_CORRECTION,
            default => null,
        };

        $version = ApplicantBioDataVersion::where('applicant_id', $requirement->applicant_id)
            ->orderByDesc('version_number')
            ->lockForUpdate()
            ->first();

        if (! $outcome || ! $version?->isPending()) {
            return [];
        }

        $applicant = Applicant::withTrashed()->findOrFail($requirement->applicant_id);
        $changes = $this->closeVersion(
            $applicant,
            $version,
            $outcome,
            $actor,
            $requirement->rejection_reason ?? $requirement->verification_note
        );

        // The checklist sends its own notice of the outcome.
        $this->recordReview($applicant, $version, null, $changes, notify: false);

        return $changes;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    private function closeVersion(Applicant $applicant, ApplicantBioDataVersion $version, string $outcome, User $actor, ?string $note): array
    {
        $changes = $outcome === ApplicantBioDataVersion::VERIFIED
            ? $this->applyToProfile($applicant, $version->data['form'] ?? [], $actor)
            : [];

        $version->update([
            'review_outcome' => $outcome,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'review_note' => $note,
            'applied_changes' => $changes ?: null,
        ]);

        return $changes;
    }

    /**
     * Writes the verified declarations onto the profile, the source every
     * other screen reads. Only values that differ, and never a blank over a
     * value on file. A change to something the matching engine scores marks
     * earlier rankings out of date, as an HR edit does - the score itself is
     * not touched here.
     */
    private function applyToProfile(Applicant $applicant, array $form, User $actor): array
    {
        $changes = BioDataForm::differencesFromProfile($applicant, $form);

        if ($changes === []) {
            return [];
        }

        $applicant->fill(array_map(fn ($pair) => $pair[1], $changes) + ['updated_by' => $actor->id]);
        $scored = $applicant->isDirty(['sex', 'height_cm']);
        $applicant->save();

        if ($scored) {
            $applicant->markScoredProfileChanged();
        }

        return $changes;
    }

    private function recordReview(
        Applicant $applicant,
        ApplicantBioDataVersion $version,
        ?array $before,
        array $profileChanges,
        bool $notify = true,
    ): void {
        $this->audit->record(
            action: 'update',
            module: 'requirements',
            recordType: ApplicantBioDataVersion::class,
            recordId: $version->id,
            oldValues: $before,
            newValues: [
                'requirement' => 'Bio-data',
                'version' => $version->version_number,
                'outcome' => $version->review_outcome,
                'note' => $version->review_note,
                'profile_fields_updated' => array_keys($profileChanges),
                'applicant_code' => $applicant->applicant_code,
            ],
        );

        if ($profileChanges !== []) {
            $this->audit->recordUpdate(
                'applicants',
                Applicant::class,
                $applicant->id,
                array_map(fn ($pair) => $pair[0], $profileChanges),
                array_map(fn ($pair) => $pair[1], $profileChanges),
            );
        }

        if ($notify) {
            $applicant->notify(new BioDataReviewed($version->review_outcome));
        }
    }

    private function assertEditable(Applicant $applicant, ?ApplicantRequirement $requirement = null): void
    {
        $requirement ??= $this->requirement($applicant);
        $reason = $this->lockReason($requirement, $applicant->latestBioDataVersion);

        abort_if($reason !== null, 409, $reason);
    }

    // ------------------------------------------------------------ presenters

    public function forPortal(Applicant $applicant): array
    {
        // load, not loadMissing: a model held across requests (a login's
        // applicant) must not answer from before the last submission.
        $applicant->load(['bioData', 'latestBioDataVersion', 'bioDataVersions']);
        $requirement = $this->requirement($applicant);
        $latest = $applicant->latestBioDataVersion;
        $state = $this->state($requirement, $latest, $applicant->bioData);
        $form = $this->currentForm($applicant);
        $lock = $this->lockReason($requirement, $latest);

        return [
            'state' => $state,
            'state_label' => self::stateLabel($state),
            'can_edit' => $lock === null && (bool) $this->requirementType()?->active_flag,
            'lock_reason' => $lock,
            'profile' => BioDataForm::profileValues($applicant),
            'form' => $form,
            'draft_saved_at' => $applicant->bioData?->draft_saved_at?->toIso8601String(),
            'missing' => BioDataForm::missing($form, $applicant),
            'options' => BioDataForm::options(),
            // Only what the applicant needs: when, and what to fix.
            'versions' => $applicant->bioDataVersions->map(fn (ApplicantBioDataVersion $v) => [
                'version_number' => $v->version_number,
                'submitted_at' => $v->submitted_at?->toIso8601String(),
                'review_outcome' => $v->review_outcome,
                'reviewed_at' => $v->reviewed_at?->toIso8601String(),
                'review_note' => $v->review_outcome === ApplicantBioDataVersion::NEEDS_CORRECTION ? $v->review_note : null,
            ])->values(),
            'submitted' => $latest ? BioDataForm::normalise($latest->data['form'] ?? []) : null,
            // Complete already through a paper copy or an old upload: the
            // requirement stays done while the form is filled in.
            'earlier_verification' => self::verifiedEarlier($requirement)
                ? ['verified_at' => $requirement->verified_at?->toIso8601String()]
                : null,
            'signed_copy_required' => (bool) config('empower.bio_data.signed_copy_required', false),
        ];
    }

    public function forStaff(Applicant $applicant, User $viewer): array
    {
        $applicant->load(['bioData', 'latestBioDataVersion', 'educations', 'experiences']);
        $versions = $applicant->bioDataVersions()->with(['submitter', 'reviewer'])->get();
        $requirement = $this->requirement($applicant)?->load(['requirementType', 'verifier', 'firstViewer', 'history.verifier', 'history.replacer']);
        $requirement?->setRelation('applicant', $applicant);
        $latest = $versions->first();
        $state = $this->state($requirement, $latest, $applicant->bioData);

        return [
            'state' => $state,
            'state_label' => self::stateLabel($state),
            'requirement' => $requirement ? new ApplicantRequirementResource($requirement) : null,
            // Whether the applicant has started, never what they wrote.
            'has_draft' => $applicant->bioData?->draft !== null,
            'draft_saved_at' => $applicant->bioData?->draft_saved_at?->toIso8601String(),
            'can_review' => $viewer->can('requirements.verify') && (bool) $latest?->isPending(),
            'profile_now' => [
                ...BioDataForm::profileValues($applicant),
                ...$applicant->only(BioDataForm::DECLARED_FIELDS),
            ],
            'versions' => $versions->map(fn (ApplicantBioDataVersion $v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'submitted_at' => $v->submitted_at?->toIso8601String(),
                'submitted_by' => $v->submitter?->full_name,
                'review_outcome' => $v->review_outcome,
                'reviewed_at' => $v->reviewed_at?->toIso8601String(),
                'reviewed_by' => $v->reviewer?->full_name,
                'review_note' => $v->review_note,
                'applied_changes' => $v->applied_changes ?? [],
                'data' => $v->data,
                // For the one awaiting review: what verifying would change.
                'would_change' => $v->isPending()
                    ? BioDataForm::differencesFromProfile($applicant, $v->data['form'] ?? [])
                    : null,
            ])->values(),
            // What matching scores, recorded by HR - shown for comparison only.
            'recorded_qualifications' => [
                'educations' => $applicant->educations->map->only(['education_level', 'school_name', 'course_program', 'graduation_year'])->values(),
                'experiences' => $applicant->experiences->map->only(['company_name', 'position_title', 'months_experience'])->values(),
            ],
            'labels' => BioDataForm::LABELS,
            'options' => BioDataForm::options(),
            'signed_copy_required' => (bool) config('empower.bio_data.signed_copy_required', false),
        ];
    }

    // -------------------------------------------------------------------- PDF

    /**
     * The printable Bio-Data, rendered on request from what is stored - a
     * version, or for the applicant before they submit, their saved draft.
     * Never written to storage, so there is no lasting link to it.
     */
    public function pdf(Applicant $applicant, ?ApplicantBioDataVersion $version, bool $draft = false): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::loadHTML($this->pdfHtml($applicant, $version, $draft));

        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', false);

        return $pdf;
    }

    /** The page the PDF is drawn from, kept apart so it can be checked as text. */
    public function pdfHtml(Applicant $applicant, ?ApplicantBioDataVersion $version, bool $draft = false): string
    {
        $form = $version
            ? BioDataForm::normalise($version->data['form'] ?? [])
            : $this->currentForm($applicant);

        // The version's own copy of the profile, so an old version prints as
        // it was sent; a draft shows the profile as it is now.
        $profile = $version ? ($version->data['profile'] ?? []) : BioDataForm::profileValues($applicant);
        $version?->loadMissing('reviewer');

        $status = match (true) {
            $draft || ! $version => ['DRAFT - NOT SUBMITTED', 'Saved by the applicant and not yet sent to the office. Not reviewed.'],
            $version->review_outcome === ApplicantBioDataVersion::VERIFIED => [
                'VERIFIED',
                'Information checked by '.($version->reviewer?->full_name ?? 'CDE staff').' on '.$version->reviewed_at?->format('F j, Y').'.',
            ],
            $version->review_outcome === ApplicantBioDataVersion::NEEDS_CORRECTION => [
                'RETURNED FOR CORRECTION',
                'Returned on '.$version->reviewed_at?->format('F j, Y').'. Not verified.',
            ],
            default => ['SUBMITTED - AWAITING VERIFICATION', 'Sent on '.$version->submitted_at?->format('F j, Y').'. Not yet verified by the office.'],
        };

        return view('bio-data.document', [
            'profile' => $profile,
            'form' => $form,
            'version' => $version,
            'status' => $status,
            'generated_at' => now()->format('F j, Y g:i A'),
            'signed_copy_required' => (bool) config('empower.bio_data.signed_copy_required', false),
            'sexLabel' => BioDataForm::optionLabel('sex', $form['sex']),
            'civilStatusLabel' => BioDataForm::optionLabel('civil_status', $form['civil_status']),
            'educationLabel' => fn ($level) => BioDataForm::optionLabel('education_level', $level),
        ])->render();
    }

    public static function pdfFilename(Applicant $applicant, ?ApplicantBioDataVersion $version): string
    {
        return sprintf(
            'bio-data-%s%s.pdf',
            $applicant->applicant_code,
            $version ? '-v'.$version->version_number : '-draft'
        );
    }
}
