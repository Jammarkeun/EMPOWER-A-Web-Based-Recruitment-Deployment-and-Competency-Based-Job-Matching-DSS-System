<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Takes uploaded Bio-Data files off the checklist (requested 2 November 2026).
 *
 * The Bio-Data is filled in through the digital form now, so the uploaded
 * copies are no longer opened anywhere in the system. Nothing is deleted:
 *
 *  - each file's storage key, name and verification move into
 *    applicant_requirement_history (reason `upload_retired`), and the file
 *    itself stays in storage;
 *  - a Bio-Data that was verified stays verified, so nobody changes folder;
 *  - an upload still waiting for review, or rejected, goes back to "not
 *    sent": with its file gone there is nothing left to review, and the
 *    applicant is asked for the form instead. Neither ever counted towards
 *    a folder.
 *
 * Done with the query builder so no model event fires; the history rows are
 * written here explicitly. down() puts every file back where it was.
 */
return new class extends Migration
{
    private const REASON = 'upload_retired';

    public function up(): void
    {
        $typeId = DB::table('requirement_types')->where('requirement_code', 'bio_data')->value('id');

        if (! $typeId) {
            return;
        }

        DB::transaction(function () use ($typeId) {
            $rows = DB::table('applicant_requirements')
                ->where('requirement_type_id', $typeId)
                ->whereNotNull('file_path')
                ->get();

            $now = now();

            foreach ($rows as $row) {
                DB::table('applicant_requirement_history')->insert([
                    'applicant_requirement_id' => $row->id,
                    'applicant_id' => $row->applicant_id,
                    'requirement_type_id' => $row->requirement_type_id,
                    'status' => $row->status,
                    'file_path' => $row->file_path,
                    'file_name' => $row->file_name,
                    'expiry_date' => $row->expiry_date,
                    'verified_at' => $row->verified_at,
                    'verified_by' => $row->verified_by,
                    'verification_method' => $row->verification_method,
                    'reason' => self::REASON,
                    'replaced_at' => $now,
                    'replaced_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $detached = [
                    'file_path' => null,
                    'file_name' => null,
                    'file_mime' => null,
                    'file_size_bytes' => null,
                    'file_hash' => null,
                ];

                // Not verified: nothing is left to review, so it is not sent.
                if ($row->status !== 'verified') {
                    $detached += [
                        'status' => 'missing',
                        'submitted_at' => null,
                        'submitted_via' => null,
                        'first_viewed_at' => null,
                        'first_viewed_by' => null,
                        'rejection_reason' => null,
                        'verified_at' => null,
                        'verified_by' => null,
                        'verification_method' => null,
                        'verification_note' => null,
                    ];
                }

                DB::table('applicant_requirements')->where('id', $row->id)->update($detached);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $history = DB::table('applicant_requirement_history')->where('reason', self::REASON)->get();

            foreach ($history as $h) {
                DB::table('applicant_requirements')->where('id', $h->applicant_requirement_id)->update([
                    'file_path' => $h->file_path,
                    'file_name' => $h->file_name,
                    'status' => $h->status,
                    'verified_at' => $h->verified_at,
                    'verified_by' => $h->verified_by,
                    'verification_method' => $h->verification_method,
                ]);
            }

            DB::table('applicant_requirement_history')->where('reason', self::REASON)->delete();
        });
    }
};
