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
            $table->foreignId('final_pdf_file_id')
                ->nullable()
                ->constrained('files')
                ->nullOnDelete();
            $table->string('final_pdf_sha256', 64)->nullable();
        });

        DB::statement('ALTER TABLE files DROP CONSTRAINT files_purpose_check');

        DB::statement("
            ALTER TABLE files
            ADD CONSTRAINT files_purpose_check
            CHECK (purpose IN (
                'template',
                'draft_pdf',
                'final_pdf',
                'signed_pdf',
                'certificate',
                'identity_capture'
            ))
        ");

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_final_pdf_sha256_format_check
            CHECK (
                final_pdf_sha256 IS NULL
                OR final_pdf_sha256 ~ '^[0-9A-Fa-f]{64}$'
            )
        ");
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE contracts DROP CONSTRAINT contracts_final_pdf_sha256_format_check'
        );
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_purpose_check');

        DB::statement("
            ALTER TABLE files
            ADD CONSTRAINT files_purpose_check
            CHECK (purpose IN (
                'template',
                'draft_pdf',
                'signed_pdf',
                'certificate',
                'identity_capture'
            ))
        ");

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('final_pdf_file_id');
            $table->dropColumn('final_pdf_sha256');
        });
    }
};
