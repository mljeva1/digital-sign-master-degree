<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_status_check');

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_status_check
            CHECK (status IN (
                'draft',
                'pending_signatures',
                'partially_signed',
                'fully_signed',
                'cancelled',
                'expired',
                'archived'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_status_check');

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_status_check
            CHECK (status IN (
                'draft',
                'pending_signatures',
                'partially_signed',
                'fully_signed',
                'cancelled',
                'expired'
            ))
        ");
    }
};
