<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which documents still matter after deployment, and the history of renewals.
 *
 * 1. requirement_types.post_deployment_rule (see App\Support\DocumentCompliance).
 *    Set here only where CDE said so on 26 October 2026:
 *      - the annual medical and the health card are renewed while employed;
 *      - an NBI clearance only has to be valid upon employment.
 *    The seeded list has no NBI clearance (whether CDE collects one was an
 *    open question), so a type is matched by its code or by "NBI" in its name
 *    in case an administrator has added one. Every other type is left blank:
 *    CDE has not said, and a blank rule never flags a deployed worker.
 *
 * 2. applicant_requirement_history: the version of a document that was on
 *    file before a renewal replaced it - file, dates, who verified it. A
 *    renewal used to overwrite the row and delete the previous file, so the
 *    clearance a worker was deployed on could disappear from their record.
 *
 * Existing rows are untouched apart from setting those rules: no status is
 * changed and no expiry date is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirement_types', function (Blueprint $table) {
            // pre_deployment_only | renew_while_employed | null (not confirmed)
            $table->string('post_deployment_rule', 30)->nullable()->after('has_expiry');
        });

        DB::table('requirement_types')
            ->whereIn('requirement_code', ['medical_result', 'health_card'])
            ->update(['post_deployment_rule' => 'renew_while_employed']);

        DB::table('requirement_types')
            ->where(fn ($q) => $q->where('requirement_code', 'nbi_clearance')
                ->orWhere('requirement_name', 'like', '%NBI%'))
            ->update(['post_deployment_rule' => 'pre_deployment_only']);

        Schema::create('applicant_requirement_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_requirement_id')->constrained('applicant_requirements')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('applicants');
            $table->foreignId('requirement_type_id')->constrained('requirement_types');

            // The version as it was.
            $table->string('status', 30);
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->string('verification_method', 30)->nullable();

            // What replaced it, and when.
            $table->string('reason', 30);
            $table->timestamp('replaced_at')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['applicant_requirement_id', 'replaced_at'], 'idx_requirement_history_requirement');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table applicant_requirement_history enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_requirement_history');

        Schema::table('requirement_types', function (Blueprint $table) {
            $table->dropColumn('post_deployment_rule');
        });
    }
};
