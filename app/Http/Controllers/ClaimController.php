<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClaimController extends Controller
{
    public function index(Request $request)
    {
        $query = Claim::with('payer');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('patient_name', 'like', "%{$search}%")
                    ->orWhere('claim_number', 'like', "%{$search}%");
            });
        }

        foreach (['from' => '>=', 'to' => '<='] as $param => $operator) {
            if ($date = $request->query($param)) {
                $query->whereDate('service_date', $operator, $date);
            }
        }

        // Ordena pelo atendimento mais recente, caindo para a data de criação
        // nas guias lançadas à mão, que podem não ter data de atendimento.
        return $query->orderByRaw('service_date is null')
            ->latest('service_date')
            ->latest('id')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return Claim::create([...$data, 'status' => 'processed'])->load('payer');
    }

    public function show(Claim $claim)
    {
        return $claim->load('payer', 'items');
    }

    public function update(Request $request, Claim $claim)
    {
        $claim->update($this->validated($request));

        return $claim->load('payer');
    }

    public function destroy(Claim $claim)
    {
        $claim->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'payer_id' => [
                'required',
                Rule::exists('clinic_payers', 'payer_id')->where(fn ($query) => $query->where('clinic_id', $request->user()->clinic_id)),
            ],
            'claim_number' => ['required', 'string', 'max:255'],
            'patient_name' => ['required', 'string', 'max:255'],
            'service_date' => ['nullable', 'date', 'before_or_equal:today'],
            'total_amount' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
