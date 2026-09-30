<?php

use App\Models\Applicant;
use App\Models\Archive;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fallbackActor = User::query()->where('user_type', 'admin')->value('id')
            ?? User::query()->value('id');

        if (! $fallbackActor) {
            return;
        }

        Applicant::query()
            ->where('current_status', 'archived')
            ->orderBy('id')
            ->each(function (Applicant $applicant) use ($fallbackActor): void {
                if (Archive::where('entity_type', 'applicant')->where('entity_id', $applicant->id)->exists()) {
                    return;
                }

                $history = DB::table('application_status_history')
                    ->where('applicant_id', $applicant->id)
                    ->orderByDesc('changed_at')
                    ->get()
                    ->map(fn ($row) => [
                        'from' => $row->from_status,
                        'to' => $row->to_status,
                        'reason' => $row->reason,
                        'at' => $row->changed_at,
                    ])->all();

                $latest = $history[0] ?? [];

                Archive::create([
                    'entity_type' => 'applicant',
                    'entity_id' => $applicant->id,
                    'archive_reason' => $latest['reason'] ?? 'Archived applicant record',
                    'snapshot_json' => [
                        'person' => $applicant->only([
                            'applicant_code', 'first_name', 'middle_name', 'last_name',
                            'birth_date', 'sex', 'contact_number', 'email', 'present_address',
                        ]),
                        'status_history' => $history,
                        'archived_reason' => $latest['reason'] ?? 'Archived applicant record',
                    ],
                    'archived_by' => $latest['changed_by'] ?? $applicant->created_by ?? $fallbackActor,
                    'archived_at' => $latest['changed_at'] ?? $applicant->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Archive::where('entity_type', 'applicant')
            ->whereIn('entity_id', Applicant::where('current_status', 'archived')->pluck('id'))
            ->delete();
    }
};