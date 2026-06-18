<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('roles')) {
            throw new RuntimeException('Tablica roles ne postoji. Prvo pokreni migracije.');
        }

        if (! Schema::hasColumn('roles', 'name')) {
            throw new RuntimeException('Tablica roles mora imati stupac name za ovaj auth/role sustav.');
        }

        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Korisnik s punim pristupom administraciji sustava.',
            ],
            [
                'name' => 'employee',
                'display_name' => 'Zaposlenik',
                'description' => 'Korisnik koji koristi poslovne funkcionalnosti sustava.',
            ],
        ];

        foreach ($roles as $role) {
            $now = now();

            $values = [
                'name' => $role['name'],
            ];

            if (Schema::hasColumn('roles', 'display_name')) {
                $values['display_name'] = $role['display_name'];
            }

            if (Schema::hasColumn('roles', 'description')) {
                $values['description'] = $role['description'];
            }

            if (Schema::hasColumn('roles', 'created_at')) {
                $values['created_at'] = $now;
            }

            if (Schema::hasColumn('roles', 'updated_at')) {
                $values['updated_at'] = $now;
            }

            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                $values
            );
        }
    }
}