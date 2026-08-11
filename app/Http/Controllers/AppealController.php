<?php

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\Denial;
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
}
