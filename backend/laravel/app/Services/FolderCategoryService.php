<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\RequirementType;
use App\Support\DocumentCompliance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives an applicant's folder category from their verified documents.
 *
 * CDE Manpower Services physically files applicants into three folders, and
 * moving a folder by hand is where records went missing. Here the category is
 * always recomputed from document state and never set directly, so the digital
 * filing cannot drift out of step with the documents actually on file.
 *
 *   Folder 3  resume only, primary requirements outstanding
 *   Folder 2  all required primary requirements verified
 *   Folder 1  primary and final requirements verified, deployable
 */
class FolderCategoryService
{
    /**
     * Recompute and persist the category. Returns the resulting category.
     *
     * The applicant's row is locked while the category is worked out and
     * written (audit R-17). Folder recalculation now also runs in bulk after a
     * Settings change, and without the lock a document verification computed
     * from the old rules could land after the bulk pass and put a stale folder
     * back. With it, every folder write is computed from what was committed
     * when its lock was taken, and the last one wins correctly.
     *
     * The stored value is compared rather than the model in hand, which may
     * have been loaded before somebody else changed the folder.
     */
    public function recalculate(Applicant $applicant): string
    {
        return DB::transaction(function () use ($applicant) {
            $stored = Applicant::withTrashed()
                ->whereKey($applicant->getKey())
                ->lockForUpdate()
                ->value('folder_category');

            $applicant->unsetRelation('requirements');
            $category = $this->determine($applicant);

            if ($stored !== $category) {
                Applicant::withTrashed()
                    ->whereKey($applicant->getKey())
                    ->update(['folder_category' => $category]);
            }

            $applicant->forceFill(['folder_category' => $category])
                ->syncOriginalAttribute('folder_category');

            return $category;
        });
    }

    public function determine(Applicant $applicant): string
    {
        $required = $this->requiredTypes();

        return $this->categoryFor($required, $this->verifiedTypeIds($applicant, $required));
    }

    /**
     * The rule itself, given the currently required types and the type IDs an
     * applicant has satisfied. Shared with the bulk recalculation so the two
     * can never disagree.
     *
     * @param  Collection<int, RequirementType>  $required
     * @param  Collection<int, int>  $verified
     */
    public function categoryFor(Collection $required, Collection $verified): string
    {
        $primaryIds = $required->where('requirement_group', 'primary')->pluck('id');
        $finalIds = $required->where('requirement_group', 'final')->pluck('id');

        /*
         * A group with no required documents is satisfied, not unsatisfiable.
         *
         * These once demanded the group be non-empty, which quietly meant that
         * deactivating every final requirement — something an administrator can
         * now do from the settings screen, and would if a client stopped asking
         * for medicals — made folder_1 unreachable for everybody. Nobody could
         * be deployed, and nothing would have said why.
         */
        $primaryComplete = $primaryIds->diff($verified)->isEmpty();
        $finalComplete = $finalIds->diff($verified)->isEmpty();

        return match (true) {
            $primaryComplete && $finalComplete => 'folder_1',
            $primaryComplete => 'folder_2',
            default => 'folder_3',
        };
    }

    /**
     * A breakdown for the applicant detail screen, so HR can see exactly which
     * documents are holding someone back rather than only the resulting folder.
     */
    public function explain(Applicant $applicant): array
    {
        $required = $this->requiredTypes();
        $verified = $this->verifiedTypeIds($applicant, $required);

        $missing = fn (string $group) => $required
            ->where('requirement_group', $group)
            ->reject(fn (RequirementType $t) => $verified->contains($t->id))
            ->pluck('requirement_name')
            ->values()
            ->all();

        $category = $this->determine($applicant);
        $missingPrimary = $missing('primary');
        $missingFinal = $missing('final');

        return [
            'folder_category' => $category,
            'label' => config("empower.folders.{$category}.label"),
            'description' => config("empower.folders.{$category}.description"),
            'missing_primary' => $missingPrimary,
            'missing_final' => $missingFinal,
            'is_deployment_ready' => $category === 'folder_1',
            'reason' => $this->reason($category, $missingPrimary, $missingFinal),
        ];
    }

    private function reason(string $category, array $missingPrimary, array $missingFinal): string
    {
        return match ($category) {
            'folder_1' => 'All primary and final requirements are verified. This applicant is deployable.',
            'folder_2' => 'Primary requirements are complete. Still awaiting: '
                .implode(', ', $missingFinal).'.',
            default => 'Primary requirements are incomplete. Still awaiting: '
                .implode(', ', $missingPrimary).'.',
        };
    }

    /** @return Collection<int, RequirementType> */
    public function requiredTypes(): Collection
    {
        return RequirementType::query()
            ->active()
            ->where('is_required', true)
            ->get();
    }

    /**
     * Type IDs the applicant has genuinely satisfied. Expired documents are
     * excluded: a police clearance that lapsed while the applicant waited for a
     * posting no longer proves anything.
     *
     * Once deployed, only a lapse the worker must renew while employed counts
     * (DocumentCompliance): the clearance they were placed on lapsing later
     * does not refile them. Only required types decide a folder, so their
     * rules are the ones needed.
     *
     * @param  Collection<int, RequirementType>  $required
     */
    private function verifiedTypeIds(Applicant $applicant, Collection $required): Collection
    {
        $requirements = $applicant->relationLoaded('requirements')
            ? $applicant->requirements
            : $applicant->requirements()->get();

        $stage = DocumentCompliance::stage($applicant->current_status);
        $rules = $required->pluck('post_deployment_rule', 'id');

        return $requirements
            ->filter(fn ($r) => $r->countsAsComplete($rules->get($r->requirement_type_id), $stage))
            ->pluck('requirement_type_id');
    }
}
