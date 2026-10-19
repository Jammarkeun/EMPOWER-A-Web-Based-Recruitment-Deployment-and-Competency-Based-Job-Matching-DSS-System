<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\CriteriaCatalog;
use App\Models\FolderRecalculation;
use App\Models\RequirementType;
use App\Services\AuditService;
use App\Services\FolderRecalculationService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * System configuration.
 *
 * Split by what it governs rather than by data type, because that is how an
 * administrator thinks about it: the documents we collect, how candidates are
 * scored, and the agency's own details.
 *
 * Reading is available to anyone with settings.view, which HR holds — seeing
 * which documents are required is part of their daily work. Changing anything
 * requires settings.update, which only an administrator holds.
 */
class SettingsController extends Controller
{
    /**
     * The fields of a requirement type that decide folders. A change to any of
     * them changes the rule for every applicant at once, so stored folders are
     * recalculated straight after it (audit R-17). Its name and whether it
     * carries an expiry date do not affect anybody's folder.
     */
    private const FOLDER_FIELDS = ['is_required', 'requirement_group', 'active_flag'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly FolderRecalculationService $recalculations,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewSettings');

        return ApiResponse::success([
            'groups' => $this->settings->forDisplay(),
            'requirement_types' => RequirementType::orderBy('requirement_group')
                ->orderBy('display_order')
                ->get(),
            'criteria' => CriteriaCatalog::orderBy('criteria_name')->get(),
            // Lets the screen render read-only for HR rather than showing
            // controls that would be refused on submit.
            'can_edit' => $request->user()->can('settings.update'),
            // So a recalculation that failed or was cut off is visible, and can
            // be run again, rather than leaving folders quietly out of date.
            'folder_recalculation' => $this->recalculations->latest()?->present(),
        ]);
    }

    /**
     * Runs the folder recalculation on request: to finish one that failed or
     * was interrupted, or to check that every stored folder matches the rules.
     * Safe to repeat; it only ever corrects folders.
     */
    public function recalculateFolders(Request $request): JsonResponse
    {
        $this->authorize('manageSettings');

        $run = $this->recalculations->run('manual', $request->user(), 'Requested from Settings.');

        return ApiResponse::success(
            ['folder_recalculation' => $run->present()],
            $this->recalculationMessage('Folder recalculation finished', $run),
        );
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('manageSettings');

        $editable = SettingsService::EDITABLE;

        $data = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', Rule::in(array_keys($editable))],
            'settings.*.value' => ['present'],
        ]);

        // Each value is validated against the rules declared for its own key, so
        // a percentage cannot be set to 400 or a boolean to a sentence.
        $changes = [];
        foreach ($data['settings'] as $index => $setting) {
            $rules = $editable[$setting['key']]['rules'];

            $request->validate(
                ["settings.{$index}.value" => $rules],
                [],
                ["settings.{$index}.value" => $editable[$setting['key']]['label']]
            );

            $changes[$setting['key']] = $setting['value'];
        }

        // The recommendation bands only make sense in descending order; saved
        // out of order they would silently make one band unreachable.
        $this->assertBandsAreOrdered($changes);

        foreach ($changes as $key => $value) {
            $this->settings->set($key, $value);
        }

        $this->audit->record('update', 'settings', null, null, null, ['changed' => array_keys($changes)]);

        return ApiResponse::success(
            ['groups' => $this->settings->forDisplay()],
            'Settings saved and applied'
        );
    }

    public function reset(Request $request): JsonResponse
    {
        $this->authorize('manageSettings');

        $data = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys(SettingsService::EDITABLE))],
        ]);

        $this->settings->reset($data['key']);
        $this->audit->record('update', 'settings', null, null, null, ['reset' => $data['key']]);

        return ApiResponse::success(
            ['groups' => $this->settings->forDisplay()],
            'Setting returned to its default'
        );
    }

    // ------------------------------------------------------ requirement types

    /**
     * The documents the agency collects.
     *
     * Editable because this list genuinely changes: a client may start asking
     * for a drug test, or a clearance may stop being required. Previously this
     * lived only in a seeder, so changing it meant a developer.
     */
    public function storeRequirementType(Request $request): JsonResponse
    {
        $this->authorize('manageSettings');

        $data = $request->validate([
            'requirement_code' => ['required', 'string', 'max:50', 'unique:requirement_types,requirement_code'],
            'requirement_name' => ['required', 'string', 'max:150'],
            'requirement_group' => ['required', Rule::in(['primary', 'final'])],
            'is_required' => ['required', 'boolean'],
            'has_expiry' => ['required', 'boolean'],
        ]);

        $type = RequirementType::create($data + [
            'active_flag' => true,
            'display_order' => (int) RequirementType::max('display_order') + 1,
        ]);

        $this->audit->record('create', 'settings', RequirementType::class, $type->id, null, $data);

        // A new optional document changes nobody's folder; a new required one
        // changes everybody's who has not yet handed it in.
        $run = $type->is_required
            ? $this->recalculations->run(
                'requirement_change',
                $request->user(),
                "Required document added: {$type->requirement_name}.",
                $type,
            )
            : null;

        return ApiResponse::created(
            $type->toArray() + ['folder_recalculation' => $run?->present()],
            $this->recalculationMessage('Requirement added', $run),
        );
    }

    public function updateRequirementType(Request $request, RequirementType $requirementType): JsonResponse
    {
        $this->authorize('manageSettings');

        $data = $request->validate([
            'requirement_name' => ['sometimes', 'string', 'max:150'],
            'requirement_group' => ['sometimes', Rule::in(['primary', 'final'])],
            'is_required' => ['sometimes', 'boolean'],
            'has_expiry' => ['sometimes', 'boolean'],
            'active_flag' => ['sometimes', 'boolean'],
        ]);

        $before = $requirementType->only(array_keys($data));
        $requirementType->update($data);

        $this->audit->recordUpdate('settings', RequirementType::class, $requirementType->id, $before, $data);

        $fresh = $requirementType->fresh();

        // Only when the change could move a folder, and only after it is saved,
        // so the recalculation reads the new rule.
        $changedRule = collect(self::FOLDER_FIELDS)->contains(
            fn (string $field) => array_key_exists($field, $before) && $before[$field] !== $fresh->{$field}
        );

        $run = $changedRule
            ? $this->recalculations->run(
                'requirement_change',
                $request->user(),
                "Requirement changed: {$fresh->requirement_name}.",
                $fresh,
            )
            : null;

        return ApiResponse::success(
            $fresh->toArray() + ['folder_recalculation' => $run?->present()],
            $this->recalculationMessage('Requirement updated', $run),
        );
    }

    /**
     * Says what the follow-up recalculation did, so a failure is never silent.
     */
    private function recalculationMessage(string $done, ?FolderRecalculation $run): string
    {
        if (! $run) {
            return $done;
        }

        if ($run->status !== 'completed') {
            return "{$done}, but recalculating applicant folders did not finish. "
                .'Use "Recalculate folders" in Settings to finish it.';
        }

        return $run->applicants_changed === 0
            ? "{$done}. All {$run->applicants_checked} applicant folder(s) already matched."
            : "{$done}. {$run->applicants_changed} of {$run->applicants_checked} applicant folder(s) "
                .'were updated to match.';
    }

    // ----------------------------------------------------- competency criteria

    public function storeCriterion(Request $request): JsonResponse
    {
        $this->authorize('manageSettings');

        $data = $request->validate([
            'criteria_code' => ['required', 'string', 'max:60', 'unique:criteria_catalog,criteria_code', 'regex:/^[a-z_]+$/'],
            'criteria_name' => ['required', 'string', 'max:120'],
            'criteria_type' => ['required', Rule::in(['hard_filter', 'weighted_binary', 'weighted_scale'])],
            'value_type' => ['required', Rule::in(['boolean', 'number', 'text', 'enum'])],
            'score_direction' => ['required', Rule::in(['higher_better', 'lower_better'])],
            'description' => ['required', 'string', 'max:255'],
        ], [
            'criteria_code.regex' => 'The code may only contain lowercase letters and underscores.',
        ]);

        $criterion = CriteriaCatalog::create($data + ['is_active' => true]);

        $this->audit->record('create', 'settings', CriteriaCatalog::class, $criterion->id, null, $data);

        return ApiResponse::created($criterion, 'Criterion added');
    }

    public function updateCriterion(Request $request, CriteriaCatalog $criterion): JsonResponse
    {
        $this->authorize('manageSettings');

        $data = $request->validate([
            'criteria_name' => ['sometimes', 'string', 'max:120'],
            'criteria_type' => ['sometimes', Rule::in(['hard_filter', 'weighted_binary', 'weighted_scale'])],
            'score_direction' => ['sometimes', Rule::in(['higher_better', 'lower_better'])],
            'description' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Deactivating is allowed, but a criterion already attached to a request
        // must keep working there — existing evaluations reference it, and
        // removing it would make a past ranking unexplainable.
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $inUse = $criterion->requestCriteria()->count();
            if ($inUse > 0) {
                $data['is_active'] = false;
                $message = "Criterion deactivated. It remains on {$inUse} existing request(s) so their rankings stay explainable.";
            }
        }

        $before = $criterion->only(array_keys($data));
        $criterion->update($data);

        $this->audit->recordUpdate('settings', CriteriaCatalog::class, $criterion->id, $before, $data);

        return ApiResponse::success($criterion->fresh(), $message ?? 'Criterion updated');
    }

    /**
     * Recommendation bands must descend, or a band becomes unreachable.
     */
    private function assertBandsAreOrdered(array $changes): void
    {
        $prefix = 'empower.recommendation_bands.';

        $high = $changes[$prefix.'highly_recommended']
            ?? $this->settings->get($prefix.'highly_recommended');
        $mid = $changes[$prefix.'recommended']
            ?? $this->settings->get($prefix.'recommended');
        $low = $changes[$prefix.'reserve_pool']
            ?? $this->settings->get($prefix.'reserve_pool');

        abort_if(
            ! ($high > $mid && $mid > $low),
            422,
            'The recommendation bands must descend: highly recommended above recommended, and recommended above reserve pool.'
        );
    }
}
