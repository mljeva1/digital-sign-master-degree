<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('action', 80)->comment('npr. contract.created, signature.added');
            $table->string('entity_type', 80)->comment('npr. Contract, Signature');
            $table->unsignedBigInteger('entity_id');
            $table->boolean('success')->default(true);
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('metadata')->nullable()->comment('JSON — kontekstualni podaci događaja');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index('actor_user_id');
            $table->index('actor_customer_id');
            $table->index(['entity_type', 'entity_id']);
            $table->index('occurred_at');
        });

        DB::statement("
            ALTER TABLE audit_events
            ADD CONSTRAINT audit_events_entity_id_positive_check
            CHECK (entity_id > 0)
        ");

        DB::statement("
            ALTER TABLE audit_events
            ADD CONSTRAINT audit_events_actor_not_both_check
            CHECK (
                NOT (
                    actor_user_id IS NOT NULL
                    AND actor_customer_id IS NOT NULL
                )
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
