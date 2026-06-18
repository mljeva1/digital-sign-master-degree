<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('contract_template_id')->constrained('contract_templates')->restrictOnDelete();
            $table->foreignId('docx_file_id')->nullable()->constrained('files')->restrictOnDelete();
            $table->foreignId('pdf_file_id')->nullable()->constrained('files')->restrictOnDelete();
            $table->char('pdf_sha256', 64);
            $table->string('status', 40)->default('preview_generated');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('previewed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'status']);
        });

        DB::statement("
            ALTER TABLE contract_documents
            ADD CONSTRAINT contract_documents_pdf_sha256_format_check
            CHECK (pdf_sha256 ~ '^[0-9A-Fa-f]{64}$')
        ");

        DB::statement("
            ALTER TABLE contract_documents
            ADD CONSTRAINT contract_documents_dates_order_check
            CHECK (
                (previewed_at IS NULL OR generated_at IS NULL OR previewed_at >= generated_at)
                AND
                (approved_at IS NULL OR previewed_at IS NULL OR approved_at >= previewed_at)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_documents');
    }
};
