<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * EXPLICIT development tool — never part of the automatic DatabaseSeeder run.
 *
 * M14 hardening: there are no fallback credentials. Previously this seeder would
 * silently create a well-known shared admin (a fixed address and a fixed
 * password baked into source), which is exactly the kind of predictable account
 * that must not exist in a repository. It now REQUIRES explicit environment
 * values and fails closed without them.
 *
 * Run deliberately, never automatically:
 *   ADMIN_NAME=... ADMIN_EMAIL=... ADMIN_PASSWORD=... \
 *     php artisan db:seed --class=Database\\Seeders\\AdminUserSeeder
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('users')) {
            throw new RuntimeException('Tablica users ne postoji. Prvo pokreni migracije.');
        }

        if (! Schema::hasTable('roles')) {
            throw new RuntimeException('Tablica roles ne postoji. Prvo pokreni RoleSeeder.');
        }

        if (! Schema::hasTable('role_user')) {
            throw new RuntimeException('Pivot tablica role_user ne postoji.');
        }

        $adminName = $this->requiredEnv('ADMIN_NAME');
        $adminEmail = $this->requiredEnv('ADMIN_EMAIL');
        $adminPassword = $this->requiredEnv('ADMIN_PASSWORD');

        $user = User::query()->where('email', $adminEmail)->first();

        if (! $user) {
            $user = new User;
            $user->name = $adminName;
            $user->email = $adminEmail;
            $user->password = Hash::make($adminPassword);
            $user->save();
        } else {
            // An existing development user is never silently re-credentialed.
            $user->name = $adminName;
            $user->save();
        }

        $user->assignRole('admin');
    }

    private function requiredEnv(string $key): string
    {
        $value = env($key);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "Nedostaje obavezna environment vrijednost [{$key}]. "
                .'AdminUserSeeder nema fallback credentials i namjerno faila bez eksplicitnih vrijednosti.'
            );
        }

        return trim($value);
    }
}
