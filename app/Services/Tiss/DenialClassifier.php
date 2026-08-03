<?php

namespace App\Services\Tiss;

use App\Models\DenialReasonCode;
use Illuminate\Support\Collection;

class DenialClassifier
{
    private ?Collection $codes = null;

    public function classify(?string $reasonCode): array
    {
        if ($reasonCode === null || $reasonCode === '') {
            return [
                'category' => 'unknown',
                'reason_code' => null,
                'reason_description' => 'Motivo não informado pela operadora',
                'needs_ai_review' => true,
            ];
        }

        $match = $this->codes()->get($reasonCode);

        if ($match === null) {
            return [
                'category' => 'unknown',
                'reason_code' => $reasonCode,
                'reason_description' => 'Código não catalogado',
                'needs_ai_review' => true,
            ];
        }

        return [
            'category' => $match->category,
            'reason_code' => $match->code,
            'reason_description' => $match->description,
            'needs_ai_review' => false,
        ];
    }

    private function codes(): Collection
    {
        return $this->codes ??= DenialReasonCode::all()->keyBy('code');
    }
}
