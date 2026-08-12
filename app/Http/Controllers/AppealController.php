<?php

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\Denial;
use App\Services\Appeals\AppealGenerator;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    public function store(Request $request, Denial $denial)
    {
        $data = $request->validate([
            'text' => ['required', 'string']
        ]);

        $appeal = $denial->appeals()->latest('id')->first();

        if ($appeal) {
            $appeal->update(['ai_generated_text' => $data['text']]);
        } else {
            $appeal = Appeal::create([
                'clinic_id' => $denial->clinic_id,
                'denial_id' => $denial->id,
                'ai_generated_text' => $data['text'],
                'status' => 'draft'
            ]);
        }

        return $appeal;
    }

    public function submit(Request $request, Denial $denial)
    {
        $appeal = $denial->appeals()->latest('id')->firstOrFail();

        $appeal->update([
            'status' => 'submitted',
            'submission_channel' => 'manual',
            'submitted_by_user_id' => $request->user()->id,
            'submitted_at' => now(),
        ]);

        $denial->update(['status' => 'appealed']);

        return $appeal;
    }

    public function generate(Request $request, Denial $denial, AppealGenerator $generator)
    {
        $data = $request->validate([
            'clinical_input' => ['nullable', 'string'],
        ]);

        $result = $generator->generate($denial->load('claimItem.claim.payer', 'clinic'), $data['clinical_input'] ?? null);

        if (!$result->isGenerated()) {
            return response()->json([
                'source' => 'needs_ai',
                'message' => 'Nenhum modelo cobre este código de glosa ainda.',
            ], 422);
        }

        $appeal = $denial->appeals()->latest('id')->first();

        $payload = [
            'ai_generated_text' => $result->text,
            'generation_source' => $result->source,
            'appeal_template_id' => $result->template->id,
        ];

        if ($appeal) {
            $appeal->update($payload);
        } else {
            $appeal = Appeal::create($payload + [
                'clinic_id' => $denial->clinic_id,
                'denial_id' => $denial->id,
                'status' => 'draft',
            ]);
        }

        return response()->json([
            'appeal' => $appeal,
            'source' => $result->source,
            'template_name' => $result->template->name,
            'attachments' => $result->attachments,
            'requires_clinical_input' => $result->requiresClinicalInput,
            'missing_legal_basis' => $result->missingLegalBasis,
        ]);
    }
}
