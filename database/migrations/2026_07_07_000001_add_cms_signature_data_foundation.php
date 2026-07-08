<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE signatures ALTER COLUMN contract_party_id DROP NOT NULL');

        Schema::table('signatures', function (Blueprint $table): void {
            $table->foreignId('signature_file_id')
                ->nullable()
                ->unique()
                ->constrained('files')
                ->restrictOnDelete();
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
                'identity_capture',
                'cms_signature'
            ))
        ");
    }

    public function down(): void
    {
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

        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropUnique(['signature_file_id']);
            $table->dropConstrainedForeignId('signature_file_id');
        });

        DB::statement('ALTER TABLE signatures ALTER COLUMN contract_party_id SET NOT NULL');
    }
};
