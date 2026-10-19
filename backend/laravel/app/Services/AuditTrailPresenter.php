<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\CriteriaCatalog;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\JobPosition;
use App\Models\JobRequest;
use App\Models\PlacementDecision;
use App\Models\RequirementType;
use App\Models\Resignation;
use App\Models\Termination;
use App\Models\Training;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Models\ViolationReport;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * The audit trail in words a reviewer can read (audit R-28).
 *
 * The screen used to print an entry's new values as one line of raw JSON, so
 * nobody could see what a field changed from. Each entry is now presented as
 * who did what to which record, and field by field:
 *
 * - "changed": the entry holds the value before and after.
 * - "recorded": the entry holds only the value written. Nothing says what it
 *   replaced, so nothing here claims it changed.
 * - "previous": the entry holds only the value as it was (a removal).
 *
 * An entry that holds no values at all is an activity record ("signed in",
 * "exported a report") and says so. Nothing is inferred beyond what the
 * stored entry contains, and anything that looks like a credential is masked
 * even in entries written before such fields were redacted at source.
 */
class AuditTrailPresenter
{
    /** A key containing any of these is never shown. */
    private const SENSITIVE = ['password', 'token', 'secret'];

    private const MAX_VALUE_LENGTH = 500;

    /**
     * How each record type is named: the model, the relations it needs, and a
     * label built from them.
     */
    private function referenceTypes(): array
    {
        return [
            'Applicant' => [Applicant::class, [], fn (Applicant $a) => trim(($a->applicant_code ? $a->applicant_code.' · ' : '').$a->full_name)],
            'Employee' => [Employee::class, ['applicant'], fn (Employee $e) => trim($e->employee_number.' · '.($e->applicant?->full_name ?? ''), ' ·')],
            'Deployment' => [Deployment::class, ['employee.applicant'], fn (Deployment $d) => trim($d->deployment_code.' · '.($d->employee?->applicant?->full_name ?? ''), ' ·')],
            'Training' => [Training::class, [], fn (Training $t) => $t->training_code.' · '.$t->training_title],
            'TrainingEnrollment' => [TrainingEnrollment::class, ['training', 'applicant'], fn (TrainingEnrollment $e) => trim(($e->training?->training_code ?? 'Training').' · '.($e->applicant?->full_name ?? ''), ' ·')],
            'JobRequest' => [JobRequest::class, [], fn (JobRequest $r) => $r->request_code.' · '.$r->position_title],
            'ClientCompany' => [ClientCompany::class, [], fn (ClientCompany $c) => $c->company_name],
            'ClientDepartment' => [ClientDepartment::class, ['company'], fn (ClientDepartment $d) => trim($d->department_name.' · '.($d->company?->company_name ?? ''), ' ·')],
            'JobPosition' => [JobPosition::class, [], fn (JobPosition $p) => $p->position_title],
            'User' => [User::class, [], fn (User $u) => $u->full_name],
            'ApplicantRequirement' => [ApplicantRequirement::class, ['requirementType', 'applicant'], fn (ApplicantRequirement $r) => trim(($r->requirementType?->requirement_name ?? 'Document').' · '.($r->applicant?->full_name ?? ''), ' ·')],
            'RequirementType' => [RequirementType::class, [], fn (RequirementType $t) => $t->requirement_name],
            'EmployeeViolation' => [EmployeeViolation::class, ['employee'], fn (EmployeeViolation $v) => trim(ucwords(str_replace('_', ' ', (string) $v->violation_type)).' · '.($v->employee?->employee_number ?? ''), ' ·')],
            'ViolationReport' => [ViolationReport::class, [], fn (ViolationReport $r) => $r->report_code ?? 'Report'],
            'Resignation' => [Resignation::class, ['employee'], fn (Resignation $r) => 'Resignation · '.($r->employee?->employee_number ?? '')],
            'Termination' => [Termination::class, ['employee'], fn (Termination $t) => 'Termination · '.($t->employee?->employee_number ?? '')],
            'PlacementDecision' => [PlacementDecision::class, [], fn (PlacementDecision $p) => 'Placement answer · '.ucfirst((string) $p->decision)],
            'CriteriaCatalog' => [CriteriaCatalog::class, [], fn (CriteriaCatalog $c) => $c->criteria_name ?? $c->criteria_code ?? 'Criterion'],
        ];
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return list<array<string, mixed>>
     */
    public function present(Collection $logs): array
    {
        $references = $this->resolveReferences($logs);

        return $logs->map(function (AuditLog $log) use ($references) {
            $changes = $this->changes($log->old_values_json, $log->new_values_json);

            return [
                'id' => $log->id,
                'created_at' => $log->created_at?->toIso8601String(),
                'action_type' => $log->action_type,
                'module_key' => $log->module_key,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->full_name,
                ] : null,
                'actor_role' => $log->actor_role,
                'company_name' => $log->company?->company_name,
                'ip_address' => $log->ip_address,
                'record' => $log->record_type ? [
                    'type' => $log->record_type,
                    'id' => $log->record_id,
                    'label' => $references[$log->record_type][$log->record_id] ?? null,
                ] : null,
                'detail_level' => match (true) {
                    collect($changes)->contains('kind', 'changed') => 'changes',
                    $changes !== [] => 'values',
                    default => 'activity',
                },
                'changes' => $changes,
            ];
        })->values()->all();
    }

    /**
     * One query per record type on the page, never one per entry.
     *
     * @return array<string, array<int|string, string>>
     */
    private function resolveReferences(Collection $logs): array
    {
        $types = $this->referenceTypes();
        $resolved = [];

        foreach ($logs->whereNotNull('record_type')->groupBy('record_type') as $type => $entries) {
            if (! isset($types[$type])) {
                continue;
            }

            [$class, $with, $label] = $types[$type];
            $ids = $entries->pluck('record_id')->filter()->unique()->values();
            if ($ids->isEmpty()) {
                continue;
            }

            $query = $class::query()->with($with)->whereKey($ids->all());
            // A record removed since still deserves its name in the history.
            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $query->withTrashed();
            }

            foreach ($query->get() as $model) {
                $resolved[$type][$model->getKey()] = $label($model);
            }
        }

        return $resolved;
    }

    /**
     * Field-by-field rows, labelled by what the entry actually holds.
     *
     * @return list<array{field: string, label: string, kind: string, before: mixed, after: mixed}>
     */
    public function changes(?array $old, ?array $new): array
    {
        $old ??= [];
        $new ??= [];
        $rows = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $inOld = array_key_exists($field, $old);
            $inNew = array_key_exists($field, $new);

            $kind = match (true) {
                $inOld && $inNew && $old[$field] != $new[$field] => 'changed',
                $inNew => 'recorded',
                default => 'previous',
            };

            $rows[] = [
                'field' => (string) $field,
                'label' => ucfirst(str_replace('_', ' ', (string) $field)),
                'kind' => $kind,
                'before' => $inOld ? $this->display((string) $field, $old[$field]) : null,
                'after' => $inNew ? $this->display((string) $field, $new[$field]) : null,
            ];
        }

        return $rows;
    }

    public static function isSensitive(string $field): bool
    {
        $field = strtolower($field);

        foreach (self::SENSITIVE as $marker) {
            if (str_contains($field, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function display(string $field, mixed $value): mixed
    {
        if (self::isSensitive($field)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $value = json_encode($this->maskNested($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…';
        }

        return $value;
    }

    private function maskNested(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->maskNested($value);
            }
        }

        return $values;
    }
}
