<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Scores the skills criterion by how many of the required skills are held.
 *
 * It used to award full points for holding any one of the skills a request
 * named. A client asking for communication, customer service, teamwork, and
 * computer literacy wants all four, so an applicant with only teamwork scored
 * the same as one with every skill on the list — the matching said "match"
 * where the honest answer was "one in four".
 *
 * The catalogue type is what tells the engine how to read a list, so this is a
 * data change rather than a code switch: `weighted_scale` grades by the share
 * held, `weighted_binary` keeps "any one of these will do", which is still
 * right for certifications. An administrator can change either from Settings.
 *
 * Only the seeded wording is replaced. If someone has already edited the
 * description by hand, theirs is kept.
 */
return new class extends Migration
{
    private const OLD_DESCRIPTION = 'Whether the applicant lists any of the skills named on the request.';

    private const NEW_DESCRIPTION = 'The share of the skills named on the request that the applicant holds. '
        .'Holding three of four earns three quarters of the points.';

    public function up(): void
    {
        DB::table('criteria_catalog')
            ->where('criteria_code', 'skills')
            ->where('criteria_type', 'weighted_binary')
            ->update(['criteria_type' => 'weighted_scale', 'updated_at' => now()]);

        DB::table('criteria_catalog')
            ->where('criteria_code', 'skills')
            ->where('description', self::OLD_DESCRIPTION)
            ->update(['description' => self::NEW_DESCRIPTION]);
    }

    public function down(): void
    {
        DB::table('criteria_catalog')
            ->where('criteria_code', 'skills')
            ->update(['criteria_type' => 'weighted_binary', 'updated_at' => now()]);

        DB::table('criteria_catalog')
            ->where('criteria_code', 'skills')
            ->where('description', self::NEW_DESCRIPTION)
            ->update(['description' => self::OLD_DESCRIPTION]);
    }
};
