<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\Screens;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the three starting roles and the first administrator account.
 * Safe to run more than once: existing roles and users are left as they are.
 *
 *   php artisan db:seed --force
 *
 * Admin email/password come from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD in .env.
 * If no password is set, a random one is printed once. The admin must change it
 * at first sign-in.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $all = Screens::keys();
        $rw = fn (array $keys) => array_fill_keys($keys, ['read' => true, 'write' => true]);
        $ro = fn (array $keys) => array_fill_keys($keys, ['read' => true, 'write' => false]);

        $roles = [
            'Technical Admin' => [
                'description' => 'Manages users, roles and system settings. Full access.',
                'is_system' => true,
                'perms' => $rw($all),
            ],
            'PI / Research Coordinator' => [
                'description' => 'Enters and manages all eCRF data, signs and unlocks forms, exports data.',
                'is_system' => false,
                'perms' => $rw(array_diff($all, ['users', 'roles'])) + $ro(['users', 'roles']),
            ],
            'Cardiologist' => [
                'description' => 'View-only: study dashboard and intervention monitoring dashboard.',
                'is_system' => false,
                'perms' => $ro(['dashboard', 'participants', 'clinician_dashboard', 'alerts', 'ccsps', 'view_allocation', 'app_access']),
            ],
        ];

        // Re-runnable: creates missing roles, and adds a default row only for screens a role has
        // no permission row for yet (new screens after an upgrade). Customised rows are never changed.
        foreach ($roles as $name => $cfg) {
            $role = Role::firstOrCreate(['name' => $name], ['description' => $cfg['description'], 'is_system' => $cfg['is_system']]);
            $have = $role->permissions()->pluck('screen')->all();
            foreach (array_diff($all, $have) as $screen) {
                $p = $cfg['perms'][$screen] ?? ['read' => false, 'write' => false];
                $role->permissions()->create(['screen' => $screen, 'can_read' => $p['read'], 'can_write' => $p['write']]);
            }
        }
        // Roles created by the admin get "no access" rows for new screens, so the matrix stays complete.
        foreach (Role::whereNotIn('name', array_keys($roles))->get() as $role) {
            $have = $role->permissions()->pluck('screen')->all();
            foreach (array_diff($all, $have) as $screen) {
                $role->permissions()->create(['screen' => $screen, 'can_read' => false, 'can_write' => false]);
            }
        }

        $cfg = config('smartheart.seed_admin');
        $email = strtolower($cfg['email']);
        if (! User::where('email', $email)->exists()) {
            $password = $cfg['password'] ?: Str::password(14, symbols: false);
            User::create([
                'name' => $cfg['name'],
                'email' => $email,
                'password' => $password,
                'role_id' => Role::where('name', 'Technical Admin')->value('id'),
                'is_active' => true,
                'must_change_password' => true,
            ]);
            $this->command?->info("Admin account: {$email}");
            if (! $cfg['password']) {
                $this->command?->warn("Temporary password (shown once): {$password}");
            }
        }
    }
}
