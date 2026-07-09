<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Stvarni katalog sadrži engine_code vrijednosti do 60 znakova (173 retka > 40).
        DB::statement('
            ALTER TABLE vehicle_catalog_entries
            ALTER COLUMN engine_code TYPE varchar(80)
        ');
    }

    public function down(): void
    {
        // Namjerno bez sužavanja natrag na varchar(40): povratak bi pao ili
        // zahtijevao gubitak podataka na vrijednostima duljim od 40 znakova.
        // Projekt koristi forward-only migracije.
    }
};
