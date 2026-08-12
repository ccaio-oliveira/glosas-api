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
        Schema::create('appeal_templates', function (Blueprint $table) {
            $table->id();
            // null = template global do sistema; preenchido = versão própria da clínica
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->cascadeOnDelete();
            $table->string('name');
            $table->string('scope'); // code|group|category - nível de especificidade
            $table->string('denial_reason_code')->nullable();
            $table->string('tiss_group')->nullable();
            $table->string('category')->nullable();
            $table->longText('body');
            $table->text('legal_basis')->nullable();
            $table->json('required_attachments')->nullable();
            $table->boolean('requires_clinical_input')->default(false);
            $table->boolean('is_active')->default(true)->default(false);
            $table->timestamps();

            $table->index(['denial_reason_code', 'is_active']);
            $table->index(['tiss_group', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appeal_templates');
    }
};
