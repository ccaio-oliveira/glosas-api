<?php

namespace App\Http\Controllers;

use App\Models\Appeal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppealListController extends Controller
{
    private const AWAITING = ['submitted', 'under_review'];

    public function index(Request $request)
    {
        $query = Appeal::with('denial.claimItem.claim.payer')->latest('id');

        if ($status = $request->query('status')) {
            $status === 'awaiting'
            ? $query->whereIn('status', self::AWAITING)
            : $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->whereHas('denial.claimItem.claim', function ($q) use ($search) {
                $q->where('patient_name', 'like', "%{$search}%")
                ->orWhere('claim_number', 'like', "%{$search}%");
            });
        }

        if ($payerId = $request->query('payer_id')) {
            $query->whereHas('denial.claimItem.claim', fn ($q) => $q->where('payer_id', $payerId));
        }

        return $query->get()->map(fn (Appeal $a) => $this->present($a));
    }

    public function summary()
    {
        $all = Appeal::with('denial')->get();
        $awaiting = $all->whereIn('status', self::AWAITING);
        $accepted = $all->where('status', 'accepted');
        $answered = $all->whereIn('status', ['accepted', 'rejected']);

        $avgDays = Appeal::whereNotNull('submitted_at')->whereNotNull('responded_at')
        ->select(DB::raw('AVG(DATEDIFF(responded_at,submitted_at)) as avg_days'))
        ->value('avg_days');

        return [
            'total' => $all->count(),
            'draft' => $all->where('status', 'draft')->count(),
            'awaiting_count' => $awaiting->count(),
            'awaiting_amount' => (float) $awaiting->sum(fn ($a) => (float) ($a->denial->amount ?? 0)),
            'recovered_count' => $accepted->count(),
            'recovered_amount' => (float) $accepted->sum(fn ($a) => (float) ($a->denial->amount ?? 0)),
            'success_rate' => $answered->count() ? round($accepted->count() / $answered->count() * 100) : null,
            'avg_response_days' => $avgDays !== null ? (int) round($avgDays) : null,
        ];
    }

    public function updateStatus(Request $request, Appeal $appeal)
    {
        $data = $request->validate([
            'status' => ['required', 'in:draft,submitted,under_review,accepted,rejected'],
        ]);

        $isTerminal = in_array($data['status'], ['accepted', 'rejected'], true);

        $appeal->update([
            'status' => $data['status'],
            'responded_at' => $isTerminal ? ($appeal->responded_at ?? now()) : null,
        ]);

        $denialStatus = match ($data['status']) {
            'accepted' => 'recovered',
            'rejected' => 'rejected',
            'submitted', 'under_review' => 'appealed',
            default => null
        };

        if ($denialStatus) {
            $appeal->denial?->update(['status' => $denialStatus]);
        }

        return $this->present($appeal->fresh('denial.claimItem.claim.payer'));
    }

    private function present(Appeal $appeal): array
    {
        $denial = $appeal->denial;
        $claim = $denial?->claimItem?->claim;

        $reference = $appeal->responded_at ?? now();
        $daysWaiting = $appeal->submitted_at
        ? (int) $appeal->submitted_at->diffInDays($reference)
        : null;

        return [
            'id' => $appeal->id,
            'denial_id' => $appeal->denial_id,
            'claim_number' => $claim?->claim_number,
            'patient_name' => $claim?->patient_name,
            'payer_name' => $claim?->payer?->name,
            'procedure_description' => $denial?->claimItem?->description,
            'reason_code' => $denial?->reason_code,
            'amount' => (float) ($denial?->amount ?? 0),
            'status' => $appeal->status,
            'generation_source' => $appeal->generation_source,
            'submitted_at' => $appeal->submitted_at?->toDateString(),
            'responded_at' => $appeal->responded_at?->toDateString(),
            'days_waiting' => $daysWaiting,
        ];
    }
}
