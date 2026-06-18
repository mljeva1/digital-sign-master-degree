<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('role', 20)->comment('seller, buyer, witness, agent');
            $table->string('party_type', 20)->comment('individual, company');
            $table->string('display_name', 250);
            $table->string('oib', 11);
            $table->string('address_text', 300);
            $table->string('represented_by', 250)->nullable();
            $table->foreignId('source_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('source_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('snapshot')->comment('JSON — snapshot stranke u trenutku kreiranja ugovora');
            $table->unsignedSmallInteger('party_order')->default(1);
            $table->timestamps();

            $table->unique(['contract_id', 'role', 'party_order']);
            $table->index('source_customer_id');
            $table->index('source_user_id');
        });

        DB::statement("
            ALTER TABLE contract_parties
            ADD CONSTRAINT contract_parties_role_check
            CHECK (role IN ('seller', 'buyer', 'witness', 'agent'))
        ");

        DB::statement("
            ALTER TABLE contract_parties
            ADD CONSTRAINT contract_parties_party_type_check
            CHECK (party_type IN ('individual', 'company'))
        ");

        DB::statement("
            ALTER TABLE contract_parties
            ADD CONSTRAINT contract_parties_oib_format_check
            CHECK (oib ~ '^[0-9]{11}$')
        ");

        DB::statement("
            ALTER TABLE contract_parties
            ADD CONSTRAINT contract_parties_party_order_positive_check
            CHECK (party_order >= 1)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_parties');
    }
};
