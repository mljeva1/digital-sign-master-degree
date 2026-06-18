<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->foreignId('contract_party_id')->constrained('contract_parties')->restrictOnDelete();
            $table->foreignId('certificate_id')->nullable()->constrained('certificates')->restrictOnDelete();
            $table->foreignId('signed_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('type', 30)->default('digital');
            $table->string('status', 20)->comment('pending, completed, rejected, expired');
            $table->timestampTz('signed_at')->nullable();
            $table->char('document_hash_before', 64)->comment('SHA-256 hash dokumenta prije potpisivanja');
            $table->char('document_hash_after', 64)->nullable()->comment('SHA-256 hash dokumenta nakon potpisivanja');
            $table->string('signature_reason', 255)->nullable();
            $table->string('signature_location', 150)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampsTz();

            $table->index('contract_id');
            $table->index('contract_party_id');
            $table->index('certificate_id');
            $table->index('signed_user_id');
            $table->index('signed_customer_id');
        });

        DB::statement("
            ALTER TABLE signatures
            ADD CONSTRAINT signatures_type_check
            CHECK (type IN ('digital'))
        ");

        DB::statement("
            ALTER TABLE signatures
            ADD CONSTRAINT signatures_status_check
            CHECK (status IN ('pending', 'completed', 'rejected', 'expired'))
        ");

        DB::statement("
            ALTER TABLE signatures
            ADD CONSTRAINT signatures_document_hash_before_format_check
            CHECK (document_hash_before ~ '^[0-9A-Fa-f]{64}$')
        ");

        DB::statement("
            ALTER TABLE signatures
            ADD CONSTRAINT signatures_document_hash_after_format_check
            CHECK (document_hash_after IS NULL OR document_hash_after ~ '^[0-9A-Fa-f]{64}$')
        ");

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

        DB::statement("
            ALTER TABLE signatures
            ADD CONSTRAINT signatures_signer_not_both_check
            CHECK (
                NOT (
                    signed_user_id IS NOT NULL
                    AND signed_customer_id IS NOT NULL
                )
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
