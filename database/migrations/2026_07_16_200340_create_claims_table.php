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
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('payers')->cascadeOnDelete();
            $table->string('claim_number');
            $table->string('patient_name');
            $table->string('xml_path')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('status')->default('processing'); // processing|processed|error
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
