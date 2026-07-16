<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_base_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // ans_regulation|payer_contract_clause|historical_appeal
            $table->foreignId('payer_id')->nullable()->constrained('payers')->nullOnDelete();
            $table->string('title');
            $table->longText('content');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_entries');
    }
};
