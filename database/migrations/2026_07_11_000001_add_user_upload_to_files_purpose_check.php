<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend the PostgreSQL files_purpose_check constraint with the new
     * 'user_upload' purpose for the standalone Documents module.
     *
     * Forward-only and non-destructive: it never edits existing rows and never
     * changes existing purpose values. The CHECK is dropped and re-added with
     * the full, current set of purposes plus 'user_upload'. Guarded to pgsql so
     * the in-memory SQLite test schema (which is hand-built without this CHECK)
     * is unaffected and the PostgreSQL guarantee is not weakened.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

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
                'cms_signature',
                'user_upload'
            ))
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

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
};
