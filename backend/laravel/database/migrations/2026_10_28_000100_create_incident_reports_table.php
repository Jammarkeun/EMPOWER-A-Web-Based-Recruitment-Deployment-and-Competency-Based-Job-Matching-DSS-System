<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A coordinator's report of something that happened at the client's site.
 *
 * Asked for by CDE on 26 October 2026: "report lang siya" - for example, that
 * at 12:30 everyone in the loading department was sent home because the truck
 * would not run - written so the office can read it. It is not about one
 * worker's conduct, so it has no employee and never becomes a violation; a
 * coordinator with a disciplinary matter files a violation report instead.
 *
 * Management answers with notes ("case pending", "case resolved"), kept as a
 * thread so an earlier note is never overwritten by a later one. The
 * coordinator may add follow-up notes to the same thread.
 *
 * The company is stamped from the coordinator's assignment, never taken from
 * the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_code', 50)->unique();

            $table->foreignId('client_company_id')->constrained('client_companies');
            $table->foreignId('client_department_id')->nullable()->constrained('client_departments');
            $table->foreignId('reported_by')->constrained('users');

            $table->string('title', 150);
            $table->date('incident_date');
            // Wall-clock time on site, as the coordinator reports it.
            $table->time('incident_time')->nullable();
            $table->string('location', 150)->nullable();
            $table->text('details');

            $table->string('evidence_path', 255)->nullable();
            $table->string('evidence_name', 255)->nullable();

            // draft | submitted | under_review | resolved
            $table->string('status', 30)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['client_company_id', 'status'], 'idx_incident_reports_company_status');
            $table->index('status', 'idx_incident_reports_status');
        });

        /*
         * Notes on a report, by management or the coordinator. Append-only:
         * nothing edits or deletes a note, so the thread reads as it happened.
         * A note that changed the report's status records the change beside it.
         */
        Schema::create('incident_report_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_report_id')->constrained('incident_reports')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            // The capacity they wrote in, as it was at the time.
            $table->string('author_role', 30)->nullable();
            $table->text('note');
            $table->string('status_from', 30)->nullable();
            $table->string('status_to', 30)->nullable();
            $table->timestamps();

            $table->index(['incident_report_id', 'created_at'], 'idx_incident_report_notes_report');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table incident_reports enable row level security');
            DB::statement('alter table incident_report_notes enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_report_notes');
        Schema::dropIfExists('incident_reports');
    }
};
