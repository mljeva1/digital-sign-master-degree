<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number', 50)->unique();
            $table->foreignId('template_id')->constrained('contract_templates')->restrictOnDelete();
            $table->string('status', 30)
                  ->comment('draft, pending_signatures, partially_signed, fully_signed, cancelled, expired');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('salesperson_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->string('place', 120);
            $table->date('contract_date');
            $table->decimal('price_amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->jsonb('filled_data_snapshot')->comment('JSON — snapshot podataka ugovora u trenutku generiranja');
            $table->foreignId('draft_pdf_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('draft_pdf_sha256', 64)->nullable();
            $table->foreignId('signed_pdf_file_id')->nullable()->constrained('files')->restrictOnDelete();
            $table->string('signed_pdf_sha256', 64)->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->softDeletesTz();
            $table->timestamps();

            $table->index('template_id');
            $table->index('created_by_user_id');
            $table->index('salesperson_user_id');
            $table->index('customer_id');
            $table->index('vehicle_id');
            $table->index('status');
        });

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_status_check
            CHECK (status IN ('draft', 'pending_signatures', 'partially_signed', 'fully_signed', 'cancelled', 'expired'))
        ");

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_price_amount_non_negative_check
            CHECK (price_amount >= 0)
        ");

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_currency_format_check
            CHECK (currency ~ '^[A-Z]{3}$')
        ");

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_draft_pdf_sha256_format_check
            CHECK (draft_pdf_sha256 IS NULL OR draft_pdf_sha256 ~ '^[0-9A-Fa-f]{64}$')
        ");

        DB::statement("
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_signed_pdf_sha256_format_check
            CHECK (signed_pdf_sha256 IS NULL OR signed_pdf_sha256 ~ '^[0-9A-Fa-f]{64}$')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
