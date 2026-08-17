<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archives', function (Blueprint $table) {
            $table->id();

            // applicant | employee | deployment | violation | resignation | termination
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->string('archive_reason', 190)->nullable();

            // A full snapshot taken at archive time. Keeping the values inline
            // means a historical record still reads correctly even if a client
            // company is later renamed or a department is retired.
            $table->jsonb('snapshot_json');

            $table->foreignId('archived_by')->constrained('users');
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'idx_archives_entity');
            $table->index('archived_at', 'idx_archives_archived_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable so that failed login attempts, which have no authenticated
            // actor, are still recorded.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // login | logout | create | update | delete | deployment | violation |
            // resignation | termination | export | evaluate
            $table->string('action_type', 40);
            $table->string('module_key', 80);
            $table->string('record_type', 80)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();

            $table->jsonb('old_values_json')->nullable();
            $table->jsonb('new_values_json')->nullable();

            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 80)->nullable();
            $table->timestamps();

            $table->index('actor_user_id', 'idx_audit_logs_actor');
            $table->index('action_type', 'idx_audit_logs_action');
            $table->index('module_key', 'idx_audit_logs_module');
            $table->index(['record_type', 'record_id'], 'idx_audit_logs_record');
            $table->index('created_at', 'idx_audit_logs_created_at');
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->string('report_type', 80);
            $table->jsonb('filter_json')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('export_format', 20);                  // pdf | xlsx | csv
            $table->string('status', 30)->default('queued');      // queued | processing | completed | failed
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamps();

            $table->index('status', 'idx_report_exports_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('archives');
    }
};
