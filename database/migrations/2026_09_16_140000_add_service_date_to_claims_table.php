<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            // Data do atendimento, vinda do XML. Diferente de denials.identified_at,
            // que é quando *nós* soubemos da glosa — a operadora costuma responder
            // semanas depois do atendimento, e relatório por tendência precisa da
            // data em que o trabalho foi feito, não da data do upload.
            $table->date('service_date')->nullable()->after('claim_number');
            $table->index(['clinic_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropIndex(['clinic_id', 'service_date']);
            $table->dropColumn('service_date');
        });
    }
};
