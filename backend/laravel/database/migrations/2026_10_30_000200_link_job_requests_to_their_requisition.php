<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A manpower request remembers the coordinator requisition it was made from.
 *
 * From 30 October 2026 approving a requisition creates its manpower request
 * in the same transaction, instead of HR typing the same details into a
 * second form. The request now carries the link itself, and it is unique: one
 * requisition can only ever become one manpower request, whatever the number
 * of clicks, retries or simultaneous approvals. The requisition's own
 * job_request_id is made unique for the same reason in the other direction.
 *
 * Existing rows are linked only where the requisition already names its
 * request (manpower_requisitions.job_request_id, written when HR recorded it
 * by hand). Nothing is matched on client, position or headcount: a request
 * HR recorded directly stays a direct request.
 *
 * Purely additive. Rolling back drops the column and the two indexes and
 * leaves every requisition and request as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('manpower_requisitions')
            ->whereNotNull('job_request_id')
            ->groupBy('job_request_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('job_request_id');

        // Two requisitions claiming one request need a person to decide which
        // is right; the migration will not guess.
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Manpower requests linked from more than one requisition: '.$duplicates->implode(', ')
                .'. Resolve these by hand before migrating.'
            );
        }

        Schema::table('job_requests', function (Blueprint $table) {
            $table->foreignId('manpower_requisition_id')->nullable()->after('request_source')
                ->constrained('manpower_requisitions', indexName: 'fk_job_requests_requisition');
            $table->unique('manpower_requisition_id', 'uq_job_requests_requisition');
        });

        DB::table('manpower_requisitions')
            ->whereNotNull('job_request_id')
            ->orderBy('id')
            ->get(['id', 'job_request_id'])
            ->each(fn ($row) => DB::table('job_requests')
                ->where('id', $row->job_request_id)
                ->whereNull('manpower_requisition_id')
                ->update(['manpower_requisition_id' => $row->id]));

        Schema::table('manpower_requisitions', function (Blueprint $table) {
            $table->unique('job_request_id', 'uq_requisitions_job_request');
        });
    }

    public function down(): void
    {
        // MariaDB lets the foreign key on job_request_id lean on the unique
        // index, so a plain index takes over before the unique one goes.
        if (! Schema::hasIndex('manpower_requisitions', 'idx_requisitions_job_request')) {
            Schema::table('manpower_requisitions', function (Blueprint $table) {
                $table->index('job_request_id', 'idx_requisitions_job_request');
            });
        }

        Schema::table('manpower_requisitions', function (Blueprint $table) {
            $table->dropUnique('uq_requisitions_job_request');
        });

        Schema::table('job_requests', function (Blueprint $table) {
            // The key first: MySQL will not drop an index a foreign key uses.
            $table->dropForeign('fk_job_requests_requisition');
            $table->dropUnique('uq_job_requests_requisition');
            $table->dropColumn('manpower_requisition_id');
        });
    }
};
