<?php

namespace App\Http\Controllers;

use App\Models\AppealTemplate;
use App\Models\AuditLog;
use App\Models\Denial;
use App\Support\StatusLabels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $from = $denial->status;
        $denial->update($data);

        if ($from !== $data['status']) {
            AuditLog::record(
                action: 'denial.status_changed',
                auditable: $denial,
                summary: 'Status da glosa: ' . StatusLabels::denial($from) . ' → ' . StatusLabels::denial($data['status']),
                changes: ['status' => ['from' => $from, 'to' => $data['status']]] ,
                denialId: $denial->id,
            );
        }

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

    public function templateGaps()
    {
        $covered = AppealTemplate::where('scope', 'code')->where('is_active', true)
        ->pluck('denial_reason_code')->filter()->all();

        return Denial::select('reason_code', 'reason_description', DB::raw('count(*) as total'), DB::raw('sum(amount) as amount'))
        ->whereNotNull('reason_code')
        ->whereNotIn('reason_code', $covered)
        ->groupBy('reason_code', 'reason_description')
        ->orderByDesc('total')
        ->limit(20)
        ->get();
    }

    public function audit(Denial $denial)
    {
        return AuditLog::where('denial_id', $denial->id)
        ->orderByDesc('id')
        ->get(['id', 'action', 'user_name', 'summary', 'changes', 'created_at']);
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
