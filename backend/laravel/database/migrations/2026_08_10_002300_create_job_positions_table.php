<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The roles the agency recruits for, as records rather than as typed text.
 *
 * Until now a position existed only as a string: `position_title` on a job
 * request, `preferred_position` on an applicant, `current_position_title` on an
 * employee. Nothing joined them. "Production Helper", "production helper" and
 * "Prod. Helper" were three different jobs as far as the system was concerned,
 * an applicant could ask for work the agency does not place, and correcting a
 * job title meant finding every row that had spelled it out.
 *
 * A position belongs to the client company that has the role, which is what
 * makes the design hold for the second and third client. The agency does not
 * only place production helpers, and the next company's roles must be able to
 * appear without a code change - so the applicant's list of positions is read
 * from this table and nowhere else.
 *
 * client_company_id is nullable on purpose. CDE recruits into a general pool as
 * well as against a named client, and a position with no company is one the
 * agency offers on its own account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->string('position_code', 50)->unique();

            $table->foreignId('client_company_id')->nullable()
                ->constrained('client_companies')->nullOnDelete();

            $table->string('position_title', 150);
            $table->string('description', 255)->nullable();

            /*
             * active | inactive - the same vocabulary as client companies and
             * departments, so "is this still on offer?" is asked the same way
             * everywhere.
             *
             * Only an active position is shown to an applicant. A role the
             * client has stopped hiring for is switched off rather than
             * deleted, because the job requests and deployments that reference
             * it are history and must keep reading correctly.
             */
            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            // One title per company. Without this the find-or-create used when
            // a manpower request names a new role would slowly accumulate
            // duplicates of the same job.
            $table->unique(['client_company_id', 'position_title'], 'uq_job_positions_company_title');
            $table->index('status', 'idx_job_positions_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_positions');
    }
};
