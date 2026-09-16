<?php

namespace App\Services\Reports;

use App\Models\Denial;
use App\Support\StatusLabels;
use Illuminate\Support\Collection;

/**
 * Consolida os números de recuperação de glosas de uma clínica num período.
 *
 * A agregação é feita em PHP, não em SQL, de propósito: mantém o resultado
 * idêntico em MySQL e no sqlite dos testes (DATE_FORMAT vs strftime) e o volume
 * por clínica é de centenas a poucos milhares de linhas. Se alguma clínica
 * passar da casa das dezenas de milhares, mover para GROUP BY no banco.
 */
class RecoveryReport
{
    private const MONTHS = [
        1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];

    /** Status que contam como "ainda em jogo" — nem recuperado, nem perdido. */
    private const OPEN = ['new', 'pending', 'appealed'];

    public function __construct(
        private ?string $from = null,
        private ?string $to = null,
    ) {}

    public function data(): array
    {
        $denials = Denial::with('claimItem.claim.payer')
            ->when($this->from, fn ($q) => $q->whereDate('identified_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('identified_at', '<=', $this->to))
            ->get();

        return [
            'period' => ['from' => $this->from, 'to' => $this->to],
            'totals' => $this->totals($denials),
            'by_status' => $this->byStatus($denials),
            'by_payer' => $this->byPayer($denials),
            'by_month' => $this->byMonth($denials),
        ];
    }

    private function totals(Collection $denials): array
    {
        $denied = $this->sum($denials);
        $recovered = $this->sum($denials->where('status', 'recovered'));

        return [
            'denial_count' => $denials->count(),
            'denied_amount' => $denied,
            'recovered_amount' => $recovered,
            'open_amount' => $this->sum($denials->whereIn('status', self::OPEN)),
            'lost_amount' => $this->sum($denials->where('status', 'rejected')),
            // null em vez de 0 quando não há nada: "0%" sugere fracasso,
            // "sem dados" é o que realmente aconteceu.
            'recovery_rate' => $denied > 0 ? (int) round($recovered / $denied * 100) : null,
        ];
    }

    private function byStatus(Collection $denials): array
    {
        $total = $denials->count();

        return collect(array_keys(StatusLabels::DENIAL))
            ->map(fn (string $status) => [
                'status' => $status,
                'label' => StatusLabels::denial($status),
                'count' => $c = $denials->where('status', $status)->count(),
                'amount' => $this->sum($denials->where('status', $status)),
                'share' => $total > 0 ? (int) round($c / $total * 100) : 0,
            ])
            ->all();
    }

    private function byPayer(Collection $denials): array
    {
        return $denials
            ->groupBy(fn (Denial $d) => $d->claimItem?->claim?->payer?->name ?? 'Sem convênio')
            ->map(function (Collection $group, string $name) {
                $denied = $this->sum($group);
                $recovered = $this->sum($group->where('status', 'recovered'));

                return [
                    'payer_name' => $name,
                    'denial_count' => $group->count(),
                    'denied_amount' => $denied,
                    'recovered_amount' => $recovered,
                    'recovery_rate' => $denied > 0 ? (int) round($recovered / $denied * 100) : 0,
                ];
            })
            ->sortByDesc('denied_amount')
            ->values()
            ->all();
    }

    private function byMonth(Collection $denials): array
    {
        return $denials
            ->groupBy(fn (Denial $d) => $d->identified_at?->format('Y-m') ?? '—')
            ->map(fn (Collection $group, string $month) => [
                'month' => $month,
                'label' => $this->monthLabel($month),
                'denial_count' => $group->count(),
                'denied_amount' => $this->sum($group),
                'recovered_amount' => $this->sum($group->where('status', 'recovered')),
            ])
            ->sortBy('month')
            ->values()
            ->all();
    }

    private function monthLabel(string $month): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            return $month;
        }

        return self::MONTHS[(int) $m[2]].'/'.$m[1];
    }

    private function sum(Collection $denials): float
    {
        return round((float) $denials->sum(fn (Denial $d) => (float) $d->amount), 2);
    }
}
