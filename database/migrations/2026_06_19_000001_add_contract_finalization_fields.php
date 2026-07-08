<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('finalized_snapshot_sha256', 64)->nullable();
            $table->timestampTz('finalized_at')->nullable();
        });

        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_status_check');

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_status_check
            CHECK (status IN (
                'draft',
                'finalized',
                'pending_signatures',
                'partially_signed',
                'fully_signed',
                'cancelled',
                'expired',
                'archived'
            ))
        ");

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_finalized_snapshot_sha256_format_check
            CHECK (
                finalized_snapshot_sha256 IS NULL
                OR finalized_snapshot_sha256 ~ '^[0-9A-Fa-f]{64}$'
            )
        ");
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE contracts DROP CONSTRAINT contracts_finalized_snapshot_sha256_format_check'
        );
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

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn([
                'finalized_snapshot_sha256',
                'finalized_at',
            ]);
        });
    }
};
