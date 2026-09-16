<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiss_uploads', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('path');
            $table->index(['clinic_id', 'content_hash']);
        });

        // Backfill: sem isso os uploads que já existem nunca seriam detectados
        // como duplicata do primeiro arquivo enviado depois desta migration.
        foreach (DB::table('tiss_uploads')->whereNull('content_hash')->get(['id', 'path']) as $upload) {
            try {
                if (Storage::exists($upload->path)) {
                    DB::table('tiss_uploads')
                        ->where('id', $upload->id)
                        ->update(['content_hash' => hash('sha256', Storage::get($upload->path))]);
                }
            } catch (\Throwable) {
                // arquivo sumiu do storage — segue sem hash, apenas não será detectado
            }
        }
    }

    public function down(): void
    {
        Schema::table('tiss_uploads', function (Blueprint $table) {
            $table->dropIndex(['clinic_id', 'content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
