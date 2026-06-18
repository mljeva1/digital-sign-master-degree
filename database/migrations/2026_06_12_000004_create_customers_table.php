<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->comment('individual, company');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company_name', 200)->nullable();
            $table->char('oib', 11);
            $table->string('address_line1', 200);
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100);
            $table->string('postal_code', 20);
            $table->char('country_code', 2)->default('HR');
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            $table->softDeletesTz();
            $table->timestamps();

            $table->index('oib');
        });
        DB::statement("
        ALTER TABLE customers
        ADD CONSTRAINT customers_type_check
        CHECK (type IN ('individual', 'company'))
        ");

        DB::statement("
            ALTER TABLE customers
            ADD CONSTRAINT customers_oib_format_check
            CHECK (oib ~ '^[0-9]{11}$')
        ");

        DB::statement("
            ALTER TABLE customers
            ADD CONSTRAINT customers_country_code_format_check
            CHECK (country_code ~ '^[A-Z]{2}$')
        ");
    }

    

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
