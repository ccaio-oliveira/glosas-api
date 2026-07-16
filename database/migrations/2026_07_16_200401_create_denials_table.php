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
        Schema::create('denials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_item_id')->constrained('claim_items')->cascadeOnDelete();
            $table->string('category'); // administrative|technical|linear
            $table->string('reason_code')->nullable();
            $table->string('reason_description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('new'); // new|pending|appealed|recovered|rejected
            $table->boolean('needs_ai_review')->default(false);
            $table->date('identified_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('denials');
    }
};
