<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Points manpower requests and applicants at a position record.
 *
 * The existing text columns stay. `position_title` on a request and
 * `preferred_position` on an applicant are kept as a label written at the time,
 * for two reasons: every report, export, and screen already reads them, and a
 * historical record should say what the job was called when it was raised even
 * if the position is renamed afterwards. The new column is the reference that
 * survives a rename; the old one is the wording that should not change.
 *
 * The backfill below turns the titles already on file into position records, so
 * an existing database comes out of this migration with a populated list rather
 * than an empty dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_requests', function (Blueprint $table) {
            $table->foreignId('job_position_id')->nullable()->after('client_department_id')
                ->constrained('job_positions')->nullOnDelete();
        });

        Schema::table('applicants', function (Blueprint $table) {
            // Nullable because the agency has always accepted applicants who
            // simply want work, and because records created before this column
            // existed genuinely have no answer.
            $table->foreignId('preferred_position_id')->nullable()->after('preferred_position')
                ->constrained('job_positions')->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Derives positions from the job titles already recorded.
     *
     * Written with the query builder rather than raw SQL because this project
     * runs on MySQL locally, PostgreSQL for the defense, and SQLite under test,
     * and a hand-written INSERT ... SELECT would need three spellings.
     */
    private function backfill(): void
    {
        $year = now()->year;
        $sequence = 0;

        $existing = DB::table('job_requests')
            ->select('client_company_id', 'position_title')
            ->whereNotNull('position_title')
            ->distinct()
            ->get();

        foreach ($existing as $row) {
            $title = trim((string) $row->position_title);

            if ($title === '') {
                continue;
            }

            $positionId = DB::table('job_positions')
                ->where('client_company_id', $row->client_company_id)
                ->where('position_title', $title)
                ->value('id');

            if (! $positionId) {
                $sequence++;

                $positionId = DB::table('job_positions')->insertGetId([
                    'position_code' => sprintf('POS-%d-%05d', $year, $sequence),
                    'client_company_id' => $row->client_company_id,
                    'position_title' => $title,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('job_requests')
                ->where('client_company_id', $row->client_company_id)
                ->where('position_title', $title)
                ->update(['job_position_id' => $positionId]);
        }

        /*
         * Applicants are matched by title only, and only where the match is
         * unambiguous.
         *
         * What somebody typed into a free-text box is a wish, not a reference to
         * a particular company's vacancy. Where the same title exists at two
         * companies there is no way to tell which they meant, so the record is
         * left pointing at nothing rather than at a guess.
         */
        /*
         * HAVING repeats the aggregate rather than naming the alias.
         *
         * MySQL and SQLite both let HAVING refer to a column alias from the
         * SELECT list; PostgreSQL does not, and rejects it outright. Since this
         * project runs on all three, the aggregate is written out again - which
         * is what the SQL standard asks for anyway.
         */
        $unambiguous = DB::table('job_positions')
            ->select('position_title', DB::raw('MIN(id) as position_id'))
            ->groupBy('position_title')
            ->havingRaw('COUNT(*) = 1')
            ->get();

        foreach ($unambiguous as $position) {
            DB::table('applicants')
                ->whereNull('preferred_position_id')
                ->where('preferred_position', $position->position_title)
                ->update(['preferred_position_id' => $position->position_id]);
        }
    }

    public function down(): void
    {
        Schema::table('job_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_position_id');
        });

        Schema::table('applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_position_id');
        });
    }
};
