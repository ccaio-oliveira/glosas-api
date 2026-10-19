<?php

namespace App\Services\Denials;

use App\Models\ClinicPayer;
use App\Models\Denial;
use Illuminate\Support\Collection;

/**
 * Prazo para recorrer de cada glosa.
 *
 * - Conta a partir de `identified_at` — quando a glosa chegou até a clínica
 *   pelo demonstrativo —, não da data do atendimento. É por isso que os dois
 *   campos existem separados.
 * - Só se aplica enquanto a glosa ainda pode ser contestada (nova/pendente).
 *   Depois de enviado o recurso o prazo foi cumprido; em glosa recuperada ou
 *   negada, alerta de prazo seria ruído.
 * - Sem prazo cadastrado no convênio, o status é `not_configured`. Nunca se
 *   presume um prazo padrão: ele vem do contrato de cada clínica.
 */
class AppealDeadline
{
    public const DUE_SOON_DAYS = 7;

    private const CONTESTABLE = ['new', 'pending'];

    /** @var Collection<int, int|null> dias de prazo por payer_id, da clínica corrente */
    private Collection $daysByPayer;

    public function __construct()
    {
        $this->daysByPayer = ClinicPayer::all()
            ->mapWithKeys(fn (ClinicPayer $link) => [$link->payer_id => $link->appealDeadlineDays()]);
    }

    /**
     * @return array{status: string, due_date: ?string, days_left: ?int, deadline_days: ?int}
     */
    public function for(Denial $denial): array
    {
        $payerId = $denial->claimItem?->claim?->payer_id;
        $days = $payerId ? ($this->daysByPayer[$payerId] ?? null) : null;

        $result = ['status' => 'not_applicable', 'due_date' => null, 'days_left' => null, 'deadline_days' => $days];

        if (! in_array($denial->status, self::CONTESTABLE, true)) {
            return $result;
        }

        if (! $days || ! $denial->identified_at) {
            return ['status' => 'not_configured'] + $result;
        }

        $due = $denial->identified_at->copy()->startOfDay()->addDays($days);
        $left = (int) round(now()->startOfDay()->diffInDays($due, false));

        return [
            'status' => match (true) {
                $left < 0 => 'overdue',
                $left <= self::DUE_SOON_DAYS => 'due_soon',
                default => 'on_track',
            },
            'due_date' => $due->toDateString(),
            'days_left' => $left,
            'deadline_days' => $days,
        ];
    }
}
