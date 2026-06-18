<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; 

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 120)->nullable()->unique();
            $table->unsignedInteger('version');
            $table->string('locale', 5)->default('hr-HR');
            $table->string('engine', 20)->comment('blade, twig, mustache');
            $table->foreignId('template_file_id')->constrained('files')->restrictOnDelete();
            $table->jsonb('fields_schema')->nullable()->comment('JSON — definicija polja predloška');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'version']);
        });

        DB::statement("
            ALTER TABLE contract_templates
            ADD CONSTRAINT contract_templates_engine_check
            CHECK (engine IN ('blade', 'twig', 'mustache'))
        ");

        DB::statement("
            ALTER TABLE contract_templates
            ADD CONSTRAINT contract_templates_version_positive_check
            CHECK (version >= 1)
        ");

        DB::statement("
            ALTER TABLE contract_templates
            ADD CONSTRAINT contract_templates_locale_format_check
            CHECK (locale ~ '^[a-z]{2}-[A-Z]{2}$')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
