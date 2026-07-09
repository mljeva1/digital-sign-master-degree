<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_variant_id')->comment('vehicle_search_index.vehicle_variant_id iz lokalnog SQLite kataloga');
            $table->string('make', 80);
            $table->string('model', 120);
            $table->string('generation', 120)->nullable();
            $table->string('platform_code', 60)->nullable();
            $table->string('variant_name', 160);
            $table->string('trim_name', 120)->nullable();
            $table->smallInteger('year_from')->nullable();
            $table->smallInteger('year_to')->nullable();
            $table->string('body_type', 40)->nullable();
            $table->string('fuel_type', 30)->nullable();
            $table->string('transmission_type', 30)->nullable();
            $table->string('engine_code', 40)->nullable();
            $table->integer('displacement_cc')->nullable();
            $table->integer('power_kw')->nullable();
            $table->integer('power_hp')->nullable();
            $table->text('searchable_text');
            $table->timestamps();

            $table->unique('source_variant_id');
            $table->index(['make', 'model']);
            $table->index(['year_from', 'year_to']);
            $table->index('power_kw');
            $table->index('displacement_cc');
        });

        DB::statement('
            ALTER TABLE vehicle_catalog_entries
            ADD CONSTRAINT vehicle_catalog_entries_power_kw_non_negative_check
            CHECK (power_kw IS NULL OR power_kw >= 0)
        ');

        DB::statement('
            ALTER TABLE vehicle_catalog_entries
            ADD CONSTRAINT vehicle_catalog_entries_power_hp_non_negative_check
            CHECK (power_hp IS NULL OR power_hp >= 0)
        ');

        DB::statement('
            ALTER TABLE vehicle_catalog_entries
            ADD CONSTRAINT vehicle_catalog_entries_displacement_cc_positive_check
            CHECK (displacement_cc IS NULL OR displacement_cc > 0)
        ');

        DB::statement('
            ALTER TABLE vehicle_catalog_entries
            ADD CONSTRAINT vehicle_catalog_entries_year_range_check
            CHECK (year_from IS NULL OR year_to IS NULL OR year_from <= year_to)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_catalog_entries');
    }
};
