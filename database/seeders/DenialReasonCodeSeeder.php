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
        $codes = [
            ['code' => '01', 'description' => 'Falta de autorização prévia', 'category' => 'administrative'],
            ['code' => '02', 'description' => 'Beneficiário fora de cobertura/carência', 'category' => 'administrative'],
            ['code' => '03', 'description' => 'Documentação incompleta ou divergente', 'category' => 'administrative'],
            ['code' => '10', 'description' => 'Procedimento não coberto pelo contrato', 'category' => 'technical'],
            ['code' => '11', 'description' => 'Incompatibilidade entre procedimento e CID', 'category' => 'technical'],
            ['code' => '12', 'description' => 'Material/medicamento não compatível com o procedimento', 'category' => 'technical'],
            ['code' => '20', 'description' => 'Valor cobrado divergente da tabela contratada', 'category' => 'linear'],
            ['code' => '21', 'description' => 'Quantidade cobrada acima do limite contratual', 'category' => 'linear'],
        ];

        foreach ($codes as $code) {
            DenialReasonCode::updateOrCreate(['code' => $code['code']], $code);
        }
    }
}
