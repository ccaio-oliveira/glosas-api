<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela 22 do TUSS — catálogo global da ANS, não tem clinic_id,
        // pelo mesmo motivo de denial_reason_codes (Tabela 38).
        Schema::create('tuss_procedures', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->text('description');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuss_procedures');
    }
};
