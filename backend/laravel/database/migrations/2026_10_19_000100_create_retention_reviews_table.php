<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisions taken on the retention review list (audit FN-12, R-27).
 *
 * The list itself is computed from the retention settings each time it is
 * read; what is stored here is only what a person decided about a record that
 * appeared on it - to keep it on file until a date, with the reason. Archiving
 * goes through the applicant lifecycle and leaves its own history, and nothing
 * on the list deletes anything, so "retain" is the only decision recorded.
 *
 * The policy value in force is copied onto the decision, so a later change in
 * Settings does not rewrite why a record was kept.
 *
 * Rollback: dropping the table loses only these decisions; every record they
 * concern is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_reviews', function (Blueprint $table) {
            $table->id();

            // unhired_applicant | closed_record_documents
            $table->string('category', 40);
            $table->foreignId('applicant_id')->constrained('applicants');

            $table->string('decision', 20);
            $table->text('note');
            $table->date('retain_until');

            // Months (unhired applicants) or years (legal documents) at the time.
            $table->unsignedSmallInteger('policy_value');

            $table->foreignId('reviewed_by')->constrained('users');
            // Nullable on purpose: XAMPP's MariaDB makes the first NOT NULL
            // timestamp in a table ON UPDATE CURRENT_TIMESTAMP.
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'applicant_id'], 'idx_retention_reviews_subject');
        });

        $this->closeToSupabaseDataApi();
    }

    /**
     * Like every other EMPOWER table, kept out of Supabase's public Data API
     * (see 2026_10_15_000100_lock_down_supabase_data_api): row-level security
     * on with no policies, and nothing granted to the `anon` and
     * `authenticated` roles. Laravel connects as the table owner and is not
     * affected. Only on PostgreSQL where those roles exist; elsewhere nothing.
     */
    private function closeToSupabaseDataApi(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table retention_reviews enable row level security');

        $roles = DB::select("select rolname from pg_roles where rolname in ('anon', 'authenticated')");

        foreach ($roles as $role) {
            DB::statement(sprintf('revoke all on table retention_reviews from "%s"', $role->rolname));
            DB::statement(sprintf('revoke all on sequence retention_reviews_id_seq from "%s"', $role->rolname));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_reviews');
    }
};
