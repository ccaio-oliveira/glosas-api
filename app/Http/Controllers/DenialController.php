<?php

namespace App\Http\Controllers;

use App\Models\AppealTemplate;
use App\Models\AuditLog;
use App\Models\ClaimItem;
use App\Models\Denial;
use App\Models\TussProcedure;
use App\Support\StatusLabels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        // Recorte por data do atendimento — é por ela que o faturista procura
        // quando o paciente liga. A data do upload não diz nada para ele.
        foreach (['from' => '>=', 'to' => '<='] as $param => $operator) {
            if ($date = $request->query($param)) {
                $query->whereHas('claimItem.claim', fn ($c) => $c->whereDate('service_date', $operator, $date));
            }
        }

        return $query->latest('identified_at')->get()->map(fn (Denial $d) => $this->present($d));
    }

    public function show(Denial $denial)
    {
        $denial->load('claimItem.claim.payer', 'appeals');

        return $this->present($denial) + [
            'appeal' => $denial->appeals->sortByDesc('id')->first(),
            'tuss' => $this->tussComparison($denial->claimItem),
        ];
    }

    /**
     * Compara o que a clínica mandou com a Tabela 22. Só no detalhe — na
     * listagem seria uma consulta por linha para um dado que ninguém lê ali.
     *
     * Descrição divergente da oficial é motivo comum de glosa, e código que
     * nem existe na tabela quase sempre é a própria causa da recusa.
     */
    private function tussComparison(?ClaimItem $item): ?array
    {
        if (! $item?->procedure_code) {
            return null;
        }

        $official = TussProcedure::where('code', $item->procedure_code)->first();

        return [
            'found' => $official !== null,
            'official_description' => $official?->description,
            'description_matches' => $official
                ? $this->normalize($official->description) === $this->normalize($item->description)
                : null,
        ];
    }

    /** Ignora caixa, acento, pontuação e espaço duplo — a Tabela 22 tem alguns. */
    private function normalize(?string $text): string
    {
        $ascii = Str::lower(Str::ascii((string) $text));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $ascii)));
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
            'service_date' => $claim?->service_date?->toDateString(),
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
