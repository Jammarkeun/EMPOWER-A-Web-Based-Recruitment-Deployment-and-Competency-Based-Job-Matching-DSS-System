<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When HR first opened a submitted requisition, so the list can mark the ones
 * nobody at HR has looked at yet. Cleared on every (re)submission: a requisition
 * the coordinator corrected and sent back is new to HR again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manpower_requisitions', function (Blueprint $table) {
            $table->timestamp('hr_viewed_at')->nullable()->after('submitted_at');
            $table->foreignId('hr_viewed_by')->nullable()->after('hr_viewed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('manpower_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hr_viewed_by');
            $table->dropColumn('hr_viewed_at');
        });
    }
};
