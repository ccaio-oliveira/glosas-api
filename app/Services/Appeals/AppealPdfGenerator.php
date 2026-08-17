<?php

namespace App\Services\Appeals;

use App\Models\Appeal;
use Barryvdh\DomPDF\Facade\Pdf;

class AppealPdfGenerator
{
    public function make(Appeal $appeal)
    {
        $denial = $appeal->denial()->with('claimItem.claim.payer', 'clinic')->firstOrFail();

        return Pdf::loadView('pdf.appeal', [
            'appeal' => $appeal,
            'denial' => $denial,
            'clinic' => $denial->clinic,
            'text' => $appeal->ai_generated_text ?? '',
        ])->setPaper('a4');
    }

    public function filename(Appeal $appeal): string
    {
        $claimNumber = $appeal->denial?->claimItem?->claim?->claim_number ?? 's-n';

        return "recurso-glosa-{$appeal->denial_id}-guia-{$claimNumber}.pdf";
    }

    public function store(Appeal $appeal): string
    {
        $path = "appeals/{$appeal->clinic_id}/" . $this->filename($appeal);

        \Storage::put($path, $this->make($appeal)->output());

        return $path;
    }
}
