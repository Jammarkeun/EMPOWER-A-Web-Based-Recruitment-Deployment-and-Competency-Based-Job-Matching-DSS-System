<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters. Roles must exist before users can be assigned to them, and
     * reference data must exist before any demo records can point at it.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            RequirementTypesSeeder::class,
            CriteriaCatalogSeeder::class,
            InitialUsersSeeder::class,
        ]);

        // Demo data is opt-in so that a production deployment is never seeded
        // with fictitious applicants:  php artisan db:seed --class=DemoDataSeeder
        if (app()->environment('local') && env('SEED_DEMO_DATA', false)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
