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
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('denial_id')->constrained('denials')->cascadeOnDelete();
            $table->longText('ai_generated_text')->nullable();
            $table->string('document_path')->nullable();
            $table->string('status')->default('draft'); // draft|submitted|accepted|rejected
            $table->string('submission_channel')->nullable(); // manual|automatic
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appeals');
    }
};
