<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

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

        $adminName = env('ADMIN_NAME', 'DSMD Administrator');
        $adminEmail = env('ADMIN_EMAIL', 'admin@dsmd.local');
        $adminPassword = env('ADMIN_PASSWORD', 'Admin12345!');

        $user = User::query()
            ->where('email', $adminEmail)
            ->first();

        if (! $user) {
            $user = new User();
            $user->name = $adminName;
            $user->email = $adminEmail;
            $user->password = Hash::make($adminPassword);
            $user->save();
        } else {
            $user->name = $adminName;
            $user->save();
        }

        $user->assignRole('admin');
    }
}