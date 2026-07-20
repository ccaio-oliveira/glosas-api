<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $clinic = \App\Models\Clinic::create([
            'name' => 'Clínica Exemplo',
            'cnpj' => '12345678000199',
            'current_plan' => 'professional',
            'status' => 'active',
        ]);

        \App\Models\User::factory()->create([
            'name' => 'Dra. Ana Silva',
            'email' => 'ana@clinica-exemplo.com',
            'password' => bcrypt('password'),
            'clinic_id' => $clinic->id,
            'role' => 'owner',
        ]);

        $this->call(DenialReasonCodeSeeder::class);
    }
}
