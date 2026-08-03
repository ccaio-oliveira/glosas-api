<?php

namespace Database\Seeders;

use App\Models\DenialReasonCode;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DenialReasonCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = database_path('data/denial_reason_codes.json');
        $codes = json_decode(file_get_contents($path), true);

        foreach (array_chunk($codes, 200) as $chunk) {
            DenialReasonCode::upsert($chunk, ['code'], ['description', 'category', 'tiss_group', 'tiss_group_label']);
        }

        $this->command->info(count($codes).' códigos de glosa da Tabela 38 carregados.');
    }
}
