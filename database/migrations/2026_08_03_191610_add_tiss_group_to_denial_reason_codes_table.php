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
        Schema::table('denial_reason_codes', function (Blueprint $table) {
            $table->string('tiss_group')->nullable()->after('category');
            $table->string('tiss_group_label')->nullable()->after('tiss_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('denial_reason_codes', function (Blueprint $table) {
            $table->dropColumn(['tiss_group', 'tiss_group_label']);
        });
    }
};
