<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed do ambiente E2E (glosas-ai/e2e). Tudo o que o DatabaseSeeder cria, mais uma
 * segunda clínica para o spec de isolamento entre clínicas. Dados fictícios.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $other = Clinic::create([
            'name' => 'Clínica Isolamento',
            'cnpj' => '98765432000110',
            'current_plan' => 'starter',
            'status' => 'active',
        ]);

        User::factory()->create([
            'name' => 'Dr. Bruno Teste',
            'email' => 'bruno@clinica-isolamento.test',
            'password' => bcrypt('password'),
            'clinic_id' => $other->id,
            'role' => 'owner',
        ]);
    }
}
