<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_contract_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->char('oib', 11)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamps();

            $table->index('oib');
        });

        DB::statement("
            ALTER TABLE user_contract_profiles
            ADD CONSTRAINT user_contract_profiles_oib_format_check
            CHECK (oib IS NULL OR oib ~ '^[0-9]{11}$')
        ");

        DB::statement("
            ALTER TABLE user_contract_profiles
            ADD CONSTRAINT user_contract_profiles_country_code_format_check
            CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_contract_profiles');
    }
};
