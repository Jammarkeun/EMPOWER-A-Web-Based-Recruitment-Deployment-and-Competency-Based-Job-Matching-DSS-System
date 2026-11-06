<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client company's own documents: its BIR registration, a mayor's permit, a
 * licence - whatever that company has and CDE keeps on file.
 *
 * Each document has a title the uploader types. There is deliberately no list
 * of document types and no rule that every company holds the same ones: CDE
 * has not named any document as mandatory, and different clients keep
 * different papers. Nothing here marks a company compliant or not.
 *
 * Additive only. No existing company gets a row; a company with no documents
 * simply has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_company_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies');

            // What the uploader called it, e.g. "BIR Certificate of Registration".
            $table->string('title', 150);
            $table->text('description')->nullable();
            // Both optional: plenty of documents carry no expiry, and an
            // unknown date is left empty rather than guessed.
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();

            // The stored file. The path is a random key on the private document
            // disk and is never sent to the browser; file_name is the original
            // name, kept for display only.
            $table->string('file_path', 255);
            $table->string('file_name', 190);
            $table->string('file_mime', 150);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('file_hash', 64);

            // Who put the current file there and when; changes on a replacement.
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('uploaded_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            // A removed document is hidden, not erased: the row and its file
            // are kept for the record.
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_company_id', 'deleted_at'], 'idx_client_documents_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_company_documents');
    }
};
