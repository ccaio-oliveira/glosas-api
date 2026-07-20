<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClaimController extends Controller
{
    public function index()
    {
        return Claim::with('payer')->latest()->get();
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
                Rule::exists('payers', 'id')->where(fn ($query) => $query->where('clinic_id', $request->user()->clinic_id)),
            ],
            'claim_number' => ['required', 'string', 'max:255'],
            'patient_name' => ['required', 'string', 'max:255'],
            'total_amount' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
