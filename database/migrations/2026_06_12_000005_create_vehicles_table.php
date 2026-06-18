<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_no', 50)->nullable()->unique();
            $table->char('vin', 17)->unique();
            $table->string('registration_plate', 20)->nullable();
            $table->string('make', 80);
            $table->string('model', 120);
            $table->string('variant', 120)->nullable();
            $table->date('first_registration_date')->nullable();
            $table->integer('year')->nullable();
            $table->integer('power_kw')->nullable();
            $table->integer('mileage_km')->nullable();
            $table->string('fuel_type', 20)->nullable();
            $table->string('transmission', 20)->nullable();
            $table->string('color', 50)->nullable();
            $table->jsonb('attributes')->nullable()->comment('JSON — dodatni atributi vozila');
            $table->text('notes')->nullable();
            $table->softDeletesTz();
            $table->timestamps();

            $table->index('registration_plate');
        });
        DB::statement("
        ALTER TABLE vehicles
        ADD CONSTRAINT vehicles_vin_format_check
        CHECK (vin ~ '^[A-HJ-NPR-Z0-9]{17}$')
        ");

        DB::statement("
            ALTER TABLE vehicles
            ADD CONSTRAINT vehicles_power_kw_non_negative_check
            CHECK (power_kw IS NULL OR power_kw >= 0)
        ");

        DB::statement("
            ALTER TABLE vehicles
            ADD CONSTRAINT vehicles_mileage_km_non_negative_check
            CHECK (mileage_km IS NULL OR mileage_km >= 0)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
