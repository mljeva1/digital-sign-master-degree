<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Only role definitions are seeded automatically.
     *
     * AdminUserSeeder is intentionally NOT called here (M14): it creates a
     * privileged account and now requires explicit ADMIN_* environment values,
     * so it must be run deliberately rather than as a side effect of `db:seed`.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
        ]);
    }
}
