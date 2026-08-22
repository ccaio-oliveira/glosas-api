<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTissUploadJob;
use App\Models\Appeal;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Denial;
use App\Models\TissUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReprocessTissUpload extends Command
{
    protected $signature = 'tiss:reprocess
                            {upload : ID do TissUpload}
                            {--sync : processa na hora, sem precisar de worker na fila}
                            {--force : não pede confirmação}';

    protected $description = 'Apaga as guias geradas por um upload TISS e processa o arquivo de novo';
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $upload = TissUpload::withoutGlobalScope('clinic')->find($this->argument('upload'));

        if (!$upload) {
            $this->error("Upload {$this->argument('upload')} não encontrado.");

            return self::FAILURE;
        }

        if (!Storage::exists($upload->path)) {
            $this->error("O arquivo original sumiu do storage ({$upload->path}). Não dá pra reprocessar.");

            return self::FAILURE;
        }

        $claimIds = Claim::withoutGlobalScope('clinic')->where('tiss_upload_id', $upload->id)->pluck('id');
        $itemIds = ClaimItem::withoutGlobalScope('clinic')->whereIn('claim_id', $claimIds)->pluck('id');
        $denialIds = Denial::withoutGlobalScope('clinic')->whereIn('claim_item_id', $itemIds)->pluck('id');
        $appeals = Appeal::withoutGlobalScope('clinic')->whereIn('denial_id', $denialIds)->get();

        $this->line("Arquivo: {$upload->original_filename} (clínica {$upload->clinic_id})");
        $this->line('Serão apagados: '.$claimIds->count().' guia(s), '.$itemIds->count().' item(ns), '.$denialIds->count().' glosa(s), '.$appeals->count().' recurso(s).');

        $submitted = $appeals->whereNotNull('submitted_at');

        if ($submitted->isNotEmpty() && !$this->option('force')) {
            $this->newLine();
            $this->error($submitted->count().' recurso(s) já foram enviados ao convênio.');
            $this->warn('Apagar destrói o registro do que foi efetivamente enviado. Use --force se tiver certeza.');

            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm('Confirma?', true)) {
            $this->line('Cancelado.');
            return self::SUCCESS;
        }

        foreach ($appeals as $appeal) {
            if ($appeal->document_path && Storage::exists($appeal->document_path)) {
                Storage::delete($appeal->document_path);
            }
        }

        foreach ($denialIds as $denialId) {
            AuditLog::record(
                action: 'denial.deleted_by_reprocess',
                auditable: $upload,
                summary: 'Glosa apagada no reprocessamento do arquivo "' . $upload->original_filename . '"' . ($submitted->isNotEmpty() ? ' - havia recurso já enviado ao convênio' : ''),
                denialId: $denialId,
                clinicId: $upload->clinic_id,
            );
        }

        Claim::withoutGlobalScope('clinic')->whereIn('id', $claimIds)->delete();

        $upload->update([
            'status' => 'pending',
            'claims_count' => 0,
            'denials_count' => 0,
            'error_message' => null,
        ]);

        if ($this->option('sync')) {
            try {
                ProcessTissUploadJob::dispatchSync($upload->id);
            } catch (\Throwable) {
                //
            }

            $upload->refresh();
            $this->newLine();

            $upload->status === 'processed'
            ? $this->info("Reprocessado: {$upload->claims_count} guia(s), {$upload->denials_count} glosa(s).")
            : $this->error("Falhou: {$upload->error_message}");

            return $upload->status === 'processed' ? self::SUCCESS : self::FAILURE;
        }

        ProcessTissUploadJob::dispatch($upload->id);
        $this->info('Reenfileirado. Rode `php artisan queue:work --stop-when-empty` se não houver worker ativo.');

        return self::SUCCESS;
    }
}
