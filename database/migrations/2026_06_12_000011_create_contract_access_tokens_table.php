<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique()
                  ->comment('SHA-256 hash tokena — nikad ne pohranjivati plain token');
            $table->string('purpose', 20)->comment('signing, viewing');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('contract_id');
            $table->index('token_hash');
            $table->index('expires_at');
        });

        DB::statement("
            ALTER TABLE contract_access_tokens
            ADD CONSTRAINT contract_access_tokens_token_hash_format_check
            CHECK (token_hash ~ '^[0-9A-Fa-f]{64}$')
        ");

        DB::statement("
            ALTER TABLE contract_access_tokens
            ADD CONSTRAINT contract_access_tokens_purpose_check
            CHECK (purpose IN ('signing', 'viewing'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_access_tokens');
    }
};
