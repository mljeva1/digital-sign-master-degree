<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 30)->comment('template, draft_pdf, signed_pdf, certificate, identity_capture');
            $table->string('storage_disk', 30)->comment('local, s3');
            $table->string('storage_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->comment('SHA-256 hash sadržaja datoteke');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['storage_disk', 'storage_path']);
            $table->index('sha256');
            $table->index('created_by_user_id');
        });

        DB::statement("
            ALTER TABLE files
            ADD CONSTRAINT files_purpose_check
            CHECK (purpose IN ('template', 'draft_pdf', 'signed_pdf', 'certificate', 'identity_capture'))
        ");

        DB::statement("
            ALTER TABLE files
            ADD CONSTRAINT files_storage_disk_check
            CHECK (storage_disk IN ('local', 's3'))
        ");

        DB::statement("
            ALTER TABLE files
            ADD CONSTRAINT files_size_bytes_non_negative_check
            CHECK (size_bytes IS NULL OR size_bytes >= 0)
        ");

        DB::statement("
            ALTER TABLE files
            ADD CONSTRAINT files_sha256_format_check
            CHECK (sha256 ~ '^[0-9A-Fa-f]{64}$')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
