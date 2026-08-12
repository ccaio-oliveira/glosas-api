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
        Schema::table('appeals', function (Blueprint $table) {
            $table->string('generation_source')->nullable()->after('ai_generated_text'); // template_code|template_group|template_category|ai|manual
            $table->foreignId('appeal_template_id')->nullable()->after('generation_source')->constrained('appeal_templates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropForeign(['appeal_template_id']);
            $table->dropColumn(['generation_source', 'appeal_template_id']);
        });
    }
};
