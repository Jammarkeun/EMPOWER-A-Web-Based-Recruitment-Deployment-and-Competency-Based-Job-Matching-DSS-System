<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stored_path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->string('document_type', 40)->default('auto');
            $table->string('status', 20)->index();
            $table->json('result')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scans');
    }
};
