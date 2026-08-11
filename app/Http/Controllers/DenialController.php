<?php

namespace App\Http\Controllers;

use App\Models\Denial;
use Illuminate\Http\Request;

class DenialController extends Controller
{
    public function index(Request $request)
    {
        $query = Denial::with('claimItem.claim.payer');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('claimItem.claim', function ($c) use ($search) {
                    $c->where('patient_name', 'like', "%{$search}%")
                        ->orWhere('claim_number', 'like', "%{$search}%");
                })->orWhereHas('claimItem.claim.payer', function ($c) use ($search) {
                    $c->where('name', 'like', "%{$search}%");
                });
            });
        }

        return $query->latest('identified_at')->get()->map(fn (Denial $d) => $this->present($d));
    }

    public function show(Denial $denial)
    {
        $denial->load('claimItem.claim.payer', 'appeals');

        return $this->present($denial) + [
            'appeal' => $denial->appeals->sortByDesc('id')->first(),
        ];
    }

    public function update(Request $request, Denial $denial)
    {
        $data = $request->validate([
            'status' => ['required', 'in:new,pending,appealed,recovered,rejected'],
        ]);

        $denial->update($data);

        return $this->present($denial->fresh('claimItem.claim.payer'));
    }

    public function summary()
    {
        $open = Denial::whereIn('status', ['new', 'pending', 'appealed'])->get();

        return [
            'open_count' => $open->count(),
            'open_amount' => (float) $open->sum('amount'),
            'recovered_amount' => (float) Denial::where('status', 'recovered')->sum('amount'),
            'total_amount' => (float) Denial::sum('amount'),
            'needs_ai_review_count' => Denial::where('needs_ai_review', true)->count(),
        ];
    }

    private function present(Denial $denial): array
    {
        $item = $denial->claimItem;
        $claim = $item?->claim;

        return [
            'id' => $denial->id,
            'claim_number' => $claim?->claim_number,
            'patient_name' => $claim?->patient_name,
            'payer_name' => $claim?->payer?->name,
            'procedure_code' => $item?->procedure_code,
            'procedure_description' => $item?->description,
            'billed_amount' => (float) ($item?->billed_amount ?? 0),
            'paid_amount' => (float) ($item?->paid_amount ?? 0),
            'category' => $denial->category,
            'reason_code' => $denial->reason_code,
            'reason_description' => $denial->reason_description,
            'amount' => (float) $denial->amount,
            'status' => $denial->status,
            'needs_ai_review' => $denial->needs_ai_review,
            'identified_at' => $denial->identified_at?->toDateString(),
        ];
    }
}
