<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The two accounts needed to open the system for the first time.
 *
 * Passwords are hashed through the application's configured driver rather than
 * written as literals, so there is no pre-computed hash sitting in version
 * control. Both accounts are intended to have their passwords changed before the
 * system carries real applicant data.
 */
class InitialUsersSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@cdemanpower.local'],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'password' => Hash::make(env('SEED_ADMIN_PASSWORD', 'ChangeMe123!')),
                'user_type' => 'admin',
                'is_active' => true,
            ]
        );
        // email_verified_at is intentionally outside $fillable, so it is set
        // directly rather than through mass assignment.
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->syncRoles(['admin']);

        $hr = User::updateOrCreate(
            ['email' => 'hr@cdemanpower.local'],
            [
                'first_name' => 'Human Resource',
                'last_name' => 'Officer',
                'password' => Hash::make(env('SEED_HR_PASSWORD', 'ChangeMe123!')),
                'user_type' => 'hr',
                'is_active' => true,
            ]
        );
        $hr->forceFill(['email_verified_at' => now()])->save();
        $hr->syncRoles(['hr']);

        $this->command?->warn('Seeded admin@cdemanpower.local and hr@cdemanpower.local. Change both passwords before going live.');
    }
}
