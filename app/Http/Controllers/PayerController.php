<?php

namespace App\Http\Controllers;

use App\Models\ClinicPayer;
use App\Models\Payer;
use Illuminate\Http\Request;

class PayerController extends Controller
{
    public function index(Request $request)
    {
        return ClinicPayer::with('payer')->get()->map(fn (ClinicPayer $link) => $this->present($link));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ans_registry_code' => ['nullable', 'string', 'max:255'],
            'integration_type' => ['required', 'in:manual,tiss_webservice'],
        ]);

        $payer = Payer::whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->first();

        if (!$payer) {
            $payer = Payer::create([
                'name' => $data['name'],
                'ans_registry_code' => $data['ans_registry_code'] ?? null,
            ]);
        } elseif (!$payer->ans_registry_code && !empty($data['ans_registry_code'])) {
            $payer->update(['ans_registry_code' => $data['ans_registry_code']]);
        }

        $link = ClinicPayer::updateOrCreate(
            ['clinic_id' => $request->user()->clinic_id, 'payer_id' => $payer->id],
            ['integration_type' => $data['integration_type']],
        );

        return $this->present($link->load('payer'));
    }

    public function show(Payer $payer)
    {
        return $payer;
    }

    public function update(Request $request, ClinicPayer $clinicPayer)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ans_registry_code' => ['nullable', 'string', 'max:255'],
            'integration_type' => ['required', 'in:manual,tiss_webservice'],
        ]);

        $payer = $clinicPayer->payer;
        $sharedDataChanged = $data['name'] !== $payer->name || ($data['ans_registry_code'] ?? null) !== $payer->ans_registry_code;

        if ($sharedDataChanged) {
            $usedByOtherClinics = ClinicPayer::where('payer_id', $payer->id)
                ->where('id', '!=', $clinicPayer->id)
                ->exists();

            if ($usedByOtherClinics) {
                $payer = Payer::create([
                    'name' => $data['name'],
                    'ans_registry_code' => $data['ans_registry_code'] ?? null,
                ]);

                $clinicPayer->payer_id = $payer->id;
            } else {
                $payer->update([
                    'name' => $data['name'],
                    'ans_registry_code' => $data['ans_registry_code'] ?? null,
                ]);
            }
        }

        $clinicPayer->integration_type = $data['integration_type'];
        $clinicPayer->save();

        return $this->present($clinicPayer->load('payer'));
    }

    public function destroy(ClinicPayer $clinicPayer)
    {
        $clinicPayer->delete();

        return response()->noContent();
    }

    private function present(ClinicPayer $link): array
    {
        return [
            'id' => $link->id,
            'payer_id' => $link->payer_id,
            'name' => $link->payer->name,
            'ans_registry_code' => $link->payer->ans_registry_code,
            'integration_type' => $link->integration_type,
        ];
    }
}
