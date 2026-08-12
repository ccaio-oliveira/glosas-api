<?php

namespace App\Services\Appeals;

use App\Models\AppealTemplate;
use App\Models\Denial;
use App\Models\DenialReasonCode;

class AppealTemplateResolver
{
    public function resolve(Denial $denial, ?int $clinicId = null): ?AppealTemplate
    {
        $clinicId ??= $denial->clinic_id;

        $group = $denial->reason_code
        ? DenialReasonCode::where('code', $denial->reason_code)->value('tiss_group')
        : null;

        $candidates = AppealTemplate::query()
        ->where('is_active', true)
        ->where(fn ($q) => $q->whereNull('clinic_id')->orWhere('clinic_id', $clinicId))
        ->where(function ($q) use ($denial, $group) {
            $q->where(fn ($s) => $s->where('scope', 'code')->where('denial_reason_code', $denial->reason_code));

            if ($group) {
                $q->orWhere(fn ($s) => $s->where('scope', 'group')->where('tiss_group', $group));
            }

            $q->orWhere(fn ($s) => $s->where('scope', 'category')->where('category', $denial->category));
        })
        ->get();

        return $candidates
        ->sortByDesc(fn (AppealTemplate $t) => [$t->specificity(), $t->clinic_id ? 1 : 0])
        ->first();
    }
}
