<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TussProcedureSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/tuss_procedures.json');

        if (! file_exists($path)) {
            $this->command?->warn('tuss_procedures.json não encontrado — Tabela 22 não semeada.');

            return;
        }

        $entries = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $now = now();

        $rows = collect($entries)->map(fn (array $e) => [
            'code' => $e['id'],
            'description' => trim($e['display_name']),
            // A ANS marca "sem fim de vigência" com um traço, não com vazio.
            'valid_from' => $this->date($e['extras']['inicio_vigencia'] ?? null),
            'valid_until' => $this->date($e['extras']['fim_vigencia'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('tuss_procedures')->upsert(
                $chunk->all(),
                ['code'],
                ['description', 'valid_from', 'valid_until', 'updated_at'],
            );
        }

        $this->command?->info("Tabela 22 semeada: {$rows->count()} procedimentos.");
    }

    private function date(?string $value): ?string
    {
        return $value && $value !== '-' && $value !== '' ? $value : null;
    }
}
