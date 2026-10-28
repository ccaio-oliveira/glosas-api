<?php

namespace App\Services\Denials;

use App\Models\ClinicPayer;
use App\Models\Denial;
use Carbon\CarbonImmutable;
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
 * - O prazo é do vínculo clínica-convênio, então a busca é por (clínica da
 *   glosa, convênio). Indexar só por convênio fazia duas clínicas com o mesmo
 *   convênio colidirem quando o contexto não tem clínica (job, comando).
 * - "Hoje" é o dia no fuso de negócio (`app.business_timezone`), não em UTC:
 *   às 22h em São Paulo já é amanhã em UTC, e a contagem pularia um dia.
 *   `identified_at` é só data, usada como está, sem conversão de fuso.
 *   Glosas gravadas antes do job passar a usar o fuso de negócio podem estar
 *   com até 1 dia de diferença — não foram migradas.
 */
class AppealDeadline
{
    public const DUE_SOON_DAYS = 7;

    private const CONTESTABLE = ['new', 'pending'];

    /**
     * Vínculos por clínica, carregados na primeira glosa de cada uma. Só entram
     * clínicas de glosas que o chamador já leu (e que já passaram pelo scope).
     *
     * @var array<int, Collection<int, ClinicPayer>> clinic_id => (payer_id => vínculo)
     */
    private array $linksByClinic = [];

    /**
     * @return array{status: string, due_date: ?string, days_left: ?int, deadline_days: ?int, deadline_source: ?string}
     */
    public function for(Denial $denial): array
    {
        $payerId = $denial->claimItem?->claim?->payer_id;
        $link = $payerId ? $this->linksOf($denial->clinic_id)->get($payerId) : null;
        $days = $link?->appealDeadlineDays();

        $result = [
            'status' => 'not_applicable',
            'due_date' => null,
            'days_left' => null,
            'deadline_days' => $days,
            'deadline_source' => $link?->appealDeadlineSource(),
        ];

        if (! in_array($denial->status, self::CONTESTABLE, true)) {
            return $result;
        }

        if (! $days || ! $denial->identified_at) {
            return ['status' => 'not_configured'] + $result;
        }

        $tz = config('app.business_timezone');
        $due = CarbonImmutable::createFromFormat('Y-m-d', $denial->identified_at->toDateString(), $tz)
            ->startOfDay()
            ->addDays($days);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $left = (int) round($today->diffInDays($due, false));

        return [
            'status' => match (true) {
                $left < 0 => 'overdue',
                $left <= self::DUE_SOON_DAYS => 'due_soon',
                default => 'on_track',
            },
            'due_date' => $due->toDateString(),
            'days_left' => $left,
        ] + $result;
    }

    /** @return Collection<int, ClinicPayer> */
    private function linksOf(?int $clinicId): Collection
    {
        if (! $clinicId) {
            return collect();
        }

        // Sem scope de propósito: o filtro é a clínica da própria glosa.
        return $this->linksByClinic[$clinicId] ??= ClinicPayer::withoutGlobalScope('clinic')
            ->where('clinic_id', $clinicId)
            ->get()
            ->keyBy('payer_id');
    }
}
