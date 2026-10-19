<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carries existing endorsements and answers into the new tables.
 *
 * Applicants currently in client evaluation or approved get an endorsement,
 * dated from the status change that endorsed them where the history has one.
 * Every answer stored on an applicant becomes a decision: linked to that
 * endorsement when it was given during it, and otherwise kept unlinked, as a
 * record of an answer to a placement that can no longer be identified - it no
 * longer blocks anything, but it is not lost. Rows written here are marked
 * as recorded retroactively. The applicant columns are read, never changed.
 *
 * Checked on 16 October 2026: no answers were stored on the local or Supabase
 * databases, and two local applicants were at "approved".
 */
return new class extends Migration
{
    private const ENDORSED = ['client_evaluation', 'approved'];

    public function up(): void
    {
        $now = now();

        $endorsed = DB::table('applicants')
            ->whereIn('current_status', self::ENDORSED)
            ->whereNull('deleted_at')
            ->get(['id']);

        foreach ($endorsed as $applicant) {
            if (DB::table('endorsements')->where('applicant_id', $applicant->id)->whereNull('ended_at')->exists()) {
                continue;
            }

            // The move that endorsed them: the latest into client evaluation
            // that was not a step back from "approved".
            $history = DB::table('application_status_history')
                ->where('applicant_id', $applicant->id)
                ->where('to_status', 'client_evaluation')
                ->orderByRaw("CASE WHEN from_status = 'approved' THEN 1 ELSE 0 END")
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->first();

            DB::table('endorsements')->insert([
                'applicant_id' => $applicant->id,
                'status_history_id' => $history?->id,
                'endorsed_by' => $history?->changed_by,
                'endorsed_at' => $history?->changed_at,
                'recorded_retroactively' => true,
                'note' => $history
                    ? 'Backfilled from the status history when endorsements were introduced.'
                    : 'Backfilled when endorsements were introduced; the endorsement itself was not recorded.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $answered = DB::table('applicants')
            ->whereNotNull('placement_response')
            ->get(['id', 'current_status', 'placement_response', 'placement_responded_at', 'placement_response_note', 'updated_at']);

        foreach ($answered as $applicant) {
            if (DB::table('placement_decisions')->where('applicant_id', $applicant->id)->exists()) {
                continue;
            }

            $endorsement = in_array($applicant->current_status, self::ENDORSED, true)
                ? DB::table('endorsements')->where('applicant_id', $applicant->id)->whereNull('ended_at')->orderByDesc('id')->first()
                : null;

            // Linked only if the answer was given during this endorsement.
            $linked = $endorsement
                && ($endorsement->endorsed_at === null
                    || $applicant->placement_responded_at === null
                    || $applicant->placement_responded_at >= $endorsement->endorsed_at);

            DB::table('placement_decisions')->insert([
                'applicant_id' => $applicant->id,
                'endorsement_id' => $linked ? $endorsement->id : null,
                'decision' => $applicant->placement_response,
                'note' => $applicant->placement_response_note,
                'source' => 'applicant',
                'recorded_by' => DB::table('users')->where('applicant_id', $applicant->id)->value('id'),
                'decided_at' => $applicant->placement_responded_at ?? $applicant->updated_at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Nothing to undo separately: rolling back the table migration drops
     * these rows with the tables, and the applicant columns were never
     * changed.
     */
    public function down(): void
    {
    }
};
