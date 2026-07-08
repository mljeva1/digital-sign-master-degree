<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('public_verification_token', 64)->nullable()->unique();
            $table->timestampTz('public_verification_enabled_at')->nullable();
            $table->timestampTz('public_verification_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropUnique(['public_verification_token']);
            $table->dropColumn([
                'public_verification_token',
                'public_verification_enabled_at',
                'public_verification_revoked_at',
            ]);
        });
    }
};
