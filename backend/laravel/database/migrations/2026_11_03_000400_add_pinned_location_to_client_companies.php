<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a client company's establishment actually is, pinned on a map by HR.
 *
 * Kept beside the written office address, not in place of it: the address is
 * what the agency writes on documents, the pin is where a worker goes. Both
 * columns are empty until somebody pins the location - existing companies are
 * left without one rather than given a guessed point - and a pin is always
 * both values or neither (ClientCompanyController).
 *
 * Seven decimal places is about a centimetre, more than a map click can mean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('office_address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('client_companies', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
