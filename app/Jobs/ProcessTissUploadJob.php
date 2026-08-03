<?php

namespace App\Jobs;

use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\ClinicPayer;
use App\Models\Denial;
use App\Models\DenialReasonCode;
use App\Models\ErrorLog;
use App\Models\Payer;
use App\Models\TissUpload;
use App\Services\Tiss\DenialClassifier;
use App\Services\Tiss\TissXmlParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessTissUploadJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tissUploadId) {}

    public function handle(TissXmlParser $parser, DenialClassifier $classifier): void
    {
        $upload = TissUpload::withoutGlobalScope('clinic')->findOrFail($this->tissUploadId);
        $upload->update(['status' => 'processing']);

        try {
            $parsed = $parser->parse(Storage::get($upload->path));
            $payer = $this->resolvePayer($upload->clinic_id, $parsed->ansRegistryCode);

            $claimsCount = 0;
            $denialsCount = 0;

            DB::transaction(function () use ($upload, $parsed, $payer, $classifier, &$claimsCount, &$denialsCount) {
                foreach ($parsed->claims as $parsedClaim) {
                    $total = array_sum(array_column($parsedClaim['items'], 'billed_amount'));

                    $claim = Claim::create([
                        'clinic_id' => $upload->clinic_id,
                        'payer_id' => $payer->id,
                        'tiss_upload_id' => $upload->id,
                        'claim_number' => $parsedClaim['claim_number'],
                        'patient_name' => $parsedClaim['patient_name'],
                        'xml_path' => $upload->path,
                        'total_amount' => $total,
                        'status' => 'processed',
                    ]);

                    $claimsCount++;

                    foreach ($parsedClaim['items'] as $parsedItem) {
                        $classification = $classifier->classify($parsedItem['denial_reason_code']);

                        $item = ClaimItem::create([
                            'clinic_id' => $upload->clinic_id,
                            'claim_id' => $claim->id,
                            'procedure_code' => $parsedItem['procedure_code'],
                            'description' => $parsedItem['description'],
                            'billed_amount' => $parsedItem['billed_amount'],
                            'paid_amount' => $parsedItem['paid_amount'],
                            'denied_amount' => $parsedItem['denied_amount'],
                            'denial_reason_code_id' => $classification['reason_code'] ? DenialReasonCode::where('code', $classification['reason_code'])->value('id') : null,
                        ]);

                        if ($parsedItem['denied_amount'] > 0) {
                            Denial::create([
                                'clinic_id' => $upload->clinic_id,
                                'claim_item_id' => $item->id,
                                'category' => $classification['category'],
                                'reason_code' => $classification['reason_code'],
                                'reason_description' => $classification['reason_description'],
                                'amount' => $parsedItem['denied_amount'],
                                'status' => 'new',
                                'needs_ai_review' => $classification['needs_ai_review'],
                                'identified_at' => now()->toDateString(),
                            ]);

                            $denialsCount++;
                        }
                    }
                }
            });

            $upload->update([
                'status' => 'processed',
                'claims_count' => $claimsCount,
                'denials_count' => $denialsCount,
            ]);
        } catch (Throwable $e) {
            $upload->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            ErrorLog::log(
                category: 'claim_processing',
                message: 'Falha ao processar upload TISS: ' . $e->getMessage(),
                source: self::class,
                context: ['tiss_upload_id' => $upload->id, 'file' => $upload->original_filename],
                severity: 'error',
                clinicId: $upload->clinic_id,
            );

            throw $e;
        }
    }

    private function resolvePayer(int $clinicId, ?string $ansRegistryCode): Payer
    {
        $payer = $ansRegistryCode
            ? Payer::firstOrCreate(
                ['ans_registry_code' => $ansRegistryCode],
                ['name' => 'Operadora '.$ansRegistryCode],
            )
            : Payer::firstOrCreate(['name' => 'Operadora não identificada']);

        ClinicPayer::withoutGlobalScope('clinic')->firstOrCreate(
            ['clinic_id' => $clinicId, 'payer_id' => $payer->id],
            ['integration_type' => 'manual'],
        );

        return $payer;
    }
}
