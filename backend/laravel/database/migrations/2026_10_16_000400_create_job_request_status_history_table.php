<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to a manpower request's status, with who, when and why (R-16).
 *
 * Request status used to change in place with only an audit-log line behind
 * it, and any status could follow any other - a cancelled request could be
 * reopened and deployed against with no record of why. Applicants and
 * employees already keep a status history of this shape; requests now do too.
 *
 * Existing requests get no invented history: the first row for each will be
 * its next real change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_request_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_request_id')->constrained('job_requests');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('reason', 255)->nullable();
            // manual | deployment | headcount
            $table->string('source', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index(['job_request_id', 'changed_at'], 'idx_request_status_history_request');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table job_request_status_history enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_request_status_history');
    }
};
