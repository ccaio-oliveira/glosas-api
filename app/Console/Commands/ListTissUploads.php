<?php

namespace App\Console\Commands;

use App\Models\TissUpload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

class ListTissUploads extends Command
{
    protected $signature = 'tiss:list {--failed : só os que falharam}';
    protected $description = 'Lista os uploads TISS de todas as clínicas';


    public function handle(): int
    {
        $query = TissUpload::withoutGlobalScope('clinic')->latest();

        if ($this->option('failed')) {
            $query->where('status', 'failed');
        }

        $rows = $query->limit(30)->get()->map(fn (TissUpload $u) => [
            $u->id,
            $u->clinic_id,
            $u->status,
            $u->original_filename,
            $u->claims_count,
            $u->denials_count,
            $u->created_at->format('d/m H:i'),
        ]);

        $rows->isEmpty()
        ? $this->line('Nenhum upload.')
        : $this->table(['ID', 'Clínica', 'Status', 'Arquivo', 'Guias', 'Glosas', 'Quando'], $rows);

        return self::SUCCESS;
    }
}
