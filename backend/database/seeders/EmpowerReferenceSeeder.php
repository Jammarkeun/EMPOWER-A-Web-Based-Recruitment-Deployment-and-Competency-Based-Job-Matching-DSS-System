<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmpowerReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $seedFile = database_path('seeders/sql/seed-data.sql');

        if (! file_exists($seedFile)) {
            throw new \RuntimeException("Seed file not found: {$seedFile}");
        }

        DB::unprepared(file_get_contents($seedFile));
    }
}
