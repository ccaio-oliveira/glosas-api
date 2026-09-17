<?php

namespace App\Http\Controllers;

use App\Services\Preventive\GuideInspector;
use App\Services\Preventive\PreventiveValidator;
use Illuminate\Http\Request;
use RuntimeException;

class PreventiveController extends Controller
{
    public function rules(PreventiveValidator $validator)
    {
        return [
            'active' => $validator->activeRules(),
            'unavailable' => $validator->unavailableRules(),
        ];
    }

    public function validateGuide(Request $request, GuideInspector $inspector, PreventiveValidator $validator)
    {
        $data = $request->validate([
            'xml' => ['required', 'string', 'max:5000000'],
        ]);

        try {
            $inspected = $inspector->inspect($data['xml']);
        } catch (RuntimeException $e) {
            // XML malformado é erro do usuário, não incidente do sistema: 422 em vez
            // de deixar estourar, senão entraria no painel de erros do super_admin.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $validator->validate($inspected);
    }
}
