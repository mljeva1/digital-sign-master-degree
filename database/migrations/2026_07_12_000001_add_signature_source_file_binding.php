<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M10 source-binding schema phase.
 *
 * Adds a provable link between a signature and the exact final-PDF artefact
 * it covers, via a nullable signatures.source_file_id FK to files.id. The
 * completed invariant is strengthened to require source_file_id, a pending
 * reservation invariant is added, and a partial unique index enforces
 * idempotency for active (pending/completed) owner signatures over the same
 * (contract, signer user, source file) tuple.
 *
 * Scope note: source_file_id references the EXISTING StoredFile that
 * contracts.final_pdf_file_id already points to at reservation time — no
 * redundant or dedicated PDF copy is created. This migration proves neither
 * the files.purpose = 'final_pdf' equality nor byte-level immutability of that
 * artefact; a CHECK or trigger cannot express byte immutability. Making the
 * existing final-PDF artefact immutable after a successful pending reservation
 * is the orchestration phase's responsibility, not this migration's. No
 * signing runtime, certificate registration, controller, route, UI, audit
 * event, or new file purpose is introduced here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Nullable source-file column.
        Schema::table('signatures', function (Blueprint $table): void {
            $table->foreignId('source_file_id')
                ->nullable()
                ->after('signature_file_id')
                ->constrained('files')
                ->restrictOnDelete();
        });

        // 3. Strengthen the completed invariant: a completed signature must
        //    additionally reference the final-PDF source file it covers.
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
                    AND source_file_id IS NOT NULL
                    AND document_hash_before IS NOT NULL
                    AND document_hash_after IS NOT NULL
                    AND document_hash_before = document_hash_after
                )
            )
        ");

        // 4. Pending reservation invariant: a pending row must carry enough
        //    to serve as a reliable, non-null concurrency reservation.
        DB::statement("
            ALTER TABLE signatures
            ADD CONSTRAINT signatures_pending_required_fields_check
            CHECK (
                status <> 'pending'
                OR (
                    signed_user_id IS NOT NULL
                    AND certificate_id IS NOT NULL
                    AND source_file_id IS NOT NULL
                    AND document_hash_before IS NOT NULL
                )
            )
        ");

        // 5. Idempotency / concurrency guard for active owner signatures.
        //    signed_user_id and source_file_id are guaranteed NOT NULL within
        //    this predicate by the pending/completed CHECKs above, so plain
        //    status-scoping is sufficient and not NULL-fragile.
        DB::statement("
            CREATE UNIQUE INDEX signatures_contract_user_source_active_unique
            ON signatures (contract_id, signed_user_id, source_file_id)
            WHERE status IN ('pending', 'completed')
        ");
    }

    public function down(): void
    {
        // Reverse order.
        DB::statement('DROP INDEX signatures_contract_user_source_active_unique');

        DB::statement('ALTER TABLE signatures DROP CONSTRAINT signatures_pending_required_fields_check');

        // Restore the previous completed invariant (pre-source_file_id),
        // matching 2026_07_07_000002_strengthen_completed_cms_signature_invariant.
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

        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropForeign('signatures_source_file_id_foreign');
            $table->dropColumn('source_file_id');
        });
    }
};
