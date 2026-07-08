<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE signatures DROP CONSTRAINT signatures_completed_required_fields_check');

        DB::statement("
            ALTER TABLE signatures
            ADD CONSTRAINT signatures_completed_required_fields_check
            CHECK (
                status <> 'completed'
                OR (
                    signed_at IS NOT NULL
                    AND signed_user_id IS NOT NULL
                    AND certificate_id IS NOT NULL
                    AND signature_file_id IS NOT NULL
                    AND document_hash_before IS NOT NULL
                    AND document_hash_after IS NOT NULL
                    AND document_hash_before = document_hash_after
                )
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE signatures DROP CONSTRAINT signatures_completed_required_fields_check');

        DB::statement("
            ALTER TABLE signatures
            ADD CONSTRAINT signatures_completed_required_fields_check
            CHECK (
                status <> 'completed'
                OR (
                    signed_at IS NOT NULL
                    AND certificate_id IS NOT NULL
                    AND document_hash_after IS NOT NULL
                )
            )
        ");
    }
};
