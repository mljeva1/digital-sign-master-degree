<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identity_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('captured_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 20)->comment('manual, scan, ocr, nfc');
            $table->string('source', 20)->comment('national_id, passport, driving_licence');
            $table->text('raw_text')->nullable();
            $table->text('mrz_lines')->nullable();
            $table->jsonb('parsed_data')->nullable()->comment('JSON — parsirani podaci iz dokumenta');
            $table->decimal('confidence', 5, 2)->nullable()->comment('OCR/NFC confidence 0.00-100.00');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();

            $table->foreignId('front_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('back_file_id')->nullable()->constrained('files')->nullOnDelete();

            $table->index('customer_id');
            $table->index('captured_by_user_id');
            $table->index('verified_by_user_id');
        });

        DB::statement("
            ALTER TABLE customer_identity_captures
            ADD CONSTRAINT customer_identity_captures_method_check
            CHECK (method IN ('manual', 'scan', 'ocr', 'nfc'))
        ");

        DB::statement("
            ALTER TABLE customer_identity_captures
            ADD CONSTRAINT customer_identity_captures_source_check
            CHECK (source IN ('national_id', 'passport', 'driving_licence'))
        ");

        DB::statement("
            ALTER TABLE customer_identity_captures
            ADD CONSTRAINT customer_identity_captures_confidence_range_check
            CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 100))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identity_captures');
    }
};
