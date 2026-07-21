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
        Schema::create('clinic_payers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('payers')->cascadeOnDelete();
            $table->string('integration_type')->default('manual'); // manual|tiss_webservice
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['clinic_id', 'payer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_payers');
    }
};
