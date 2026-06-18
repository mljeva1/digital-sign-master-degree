<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20)->comment('user, customer');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('label', 255);
            $table->string('subject_dn', 500)->comment('Distinguished Name subjekta');
            $table->string('issuer_dn', 500)->comment('Distinguished Name izdavatelja');
            $table->string('serial_number', 255);
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to');
            $table->char('thumbprint_sha256', 64)->unique()->comment('SHA-256 otisak certifikata');
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('owner_user_id');
            $table->index('owner_customer_id');
            $table->index('thumbprint_sha256');
            $table->index('file_id');
        });

        DB::statement("
            ALTER TABLE certificates
            ADD CONSTRAINT certificates_owner_type_check
            CHECK (owner_type IN ('user', 'customer'))
        ");

        DB::statement("
            ALTER TABLE certificates
            ADD CONSTRAINT certificates_owner_consistency_check
            CHECK (
                (owner_type = 'user' AND owner_user_id IS NOT NULL AND owner_customer_id IS NULL)
                OR
                (owner_type = 'customer' AND owner_customer_id IS NOT NULL AND owner_user_id IS NULL)
            )
        ");

        DB::statement("
            ALTER TABLE certificates
            ADD CONSTRAINT certificates_validity_period_check
            CHECK (valid_to > valid_from)
        ");

        DB::statement("
            ALTER TABLE certificates
            ADD CONSTRAINT certificates_thumbprint_sha256_format_check
            CHECK (thumbprint_sha256 ~ '^[0-9A-Fa-f]{64}$')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
