<?php

namespace App\Services\Preventive;

use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Denial;
use Illuminate\Support\Collection;

/**
 * Analisa a guia antes do envio ao convênio.
 *
 * Dois tipos de regra, com origens bem diferentes:
 *
 * 1. **Estruturais** — o que dá para conferir olhando só o XML. Campo obrigatório
 *    ausente, data no futuro, soma que não fecha.
 * 2. **Históricas** — derivadas das glosas que *esta clínica* já levou. É o que
 *    nenhum validador genérico consegue fazer, e o motivo de a análise valer algo:
 *    "esta operadora já glosou este procedimento 3 vezes pelo motivo 1402".
 *
 * O que deliberadamente NÃO é validado, por falta de insumo — ver `unavailableRules()`.
 * Nenhuma regra cita norma da ANS: fundamentação legal é curada por humano (decisão 6).
 */
class PreventiveValidator
{
    /**
     * Quando um padrão do histórico vira alerta.
     *
     * Contagem absoluta sozinha seria ruim: numa clínica grande, quase todo
     * procedimento já foi glosado alguma vez, e o faturista pararia de ler os
     * avisos. O sinal que importa é a **taxa** — e temos o denominador, porque
     * `claim_items` guarda tudo que foi faturado, não só o que foi glosado.
     * Um código glosado 1 de 2 vezes (50%) merece aviso; 1 de 50 (2%) não.
     */
    private const MIN_DENIAL_RATE = 25;

    private const ALWAYS_ALERT_FROM = 3;

    public function validate(array $inspected): array
    {
        $findings = [];

        // Arquivo errado precisa ser recusado alto e claro. Um demonstrativo de
        // retorno é estruturalmente parecido com uma guia de envio e passaria
        // sem nenhum achado — e "nenhum problema encontrado" num arquivo que
        // sequer é o certo é pior do que não ter validado nada.
        if ($this->looksLikeReturnFile($inspected)) {
            return $this->summarize([$this->finding(
                'error', 'estrutura', 'tipoTransacao',
                'Este é um demonstrativo de retorno da operadora, não um lote de guias para envio. '
                .'Para importar glosas já recebidas, use a tela de Upload TISS.',
            )], $inspected);
        }

        if (empty($inspected['guides'])) {
            $findings[] = $this->finding(
                'error', 'estrutura', 'guia',
                'Nenhuma guia encontrada no arquivo. Confira se é um lote de envio (ENVIO_LOTE_GUIAS) e não um demonstrativo de retorno.',
            );

            return $this->summarize($findings, $inspected);
        }

        $history = $this->history();

        foreach ($inspected['guides'] as $guide) {
            $findings = array_merge(
                $findings,
                $this->checkGuide($guide),
                $this->checkAgainstHistory($guide, $history),
            );
        }

        return $this->summarize($findings, $inspected);
    }

    private function looksLikeReturnFile(array $inspected): bool
    {
        if (! empty($inspected['has_denial_fields'])) {
            return true;
        }

        return str_contains(strtoupper((string) $inspected['transaction_type']), 'DEMONSTRATIVO');
    }

    private function checkGuide(array $guide): array
    {
        $number = $guide['claim_number'];
        $findings = [];

        foreach ([
            ['claim_number', 'numeroGuiaPrestador', 'Número da guia do prestador ausente.'],
            ['patient_name', 'nomeBeneficiario', 'Nome do beneficiário ausente.'],
            ['card_number', 'numeroCarteira', 'Número da carteira do beneficiário ausente.'],
            ['service_date', 'dataAtendimento', 'Data do atendimento ausente ou em formato não reconhecido.'],
        ] as [$key, $field, $message]) {
            if (blank($guide[$key])) {
                $findings[] = $this->finding('error', 'estrutura', $field, $message, $number);
            }
        }

        if ($guide['service_date'] && $guide['service_date'] > now()->toDateString()) {
            $findings[] = $this->finding(
                'error', 'estrutura', 'dataAtendimento',
                'Data do atendimento está no futuro ('.$this->br($guide['service_date']).').',
                $number,
            );
        }

        // Guia já enviada antes: número repetido é recusa na porta de entrada.
        if ($number && Claim::where('claim_number', $number)->exists()) {
            $findings[] = $this->finding(
                'warning', 'duplicidade', 'numeroGuiaPrestador',
                "Já existe uma guia {$number} registrada nesta clínica. Confirme que não é reenvio.",
                $number,
            );
        }

        if (empty($guide['procedures'])) {
            $findings[] = $this->finding('error', 'estrutura', 'procedimentos', 'A guia não tem nenhum procedimento.', $number);

            return $findings;
        }

        foreach ($guide['procedures'] as $index => $procedure) {
            $findings = array_merge($findings, $this->checkProcedure($procedure, $number, $index + 1));
        }

        return array_merge($findings, $this->checkTotals($guide));
    }

    private function checkProcedure(array $procedure, ?string $number, int $position): array
    {
        $findings = [];
        $label = $procedure['code'] ?? "procedimento {$position}";

        if (blank($procedure['code'])) {
            $findings[] = $this->finding('error', 'estrutura', 'codigoProcedimento',
                "Procedimento {$position} sem código.", $number);
        }

        if (blank($procedure['description'])) {
            $findings[] = $this->finding('warning', 'estrutura', 'descricaoProcedimento',
                "Procedimento {$label} sem descrição.", $number);
        }

        if (blank($procedure['cbo'])) {
            $findings[] = $this->finding('warning', 'estrutura', 'CBOS',
                "Procedimento {$label} sem CBO do profissional executante.", $number);
        }

        if (! $procedure['total_amount']) {
            $findings[] = $this->finding('error', 'estrutura', 'valorTotal',
                "Procedimento {$label} sem valor, ou com valor zero.", $number,
                amountAtRisk: 0.0);
        }

        // Aritmética do próprio item: quantidade × unitário tem que bater com o total.
        $unit = $procedure['unit_amount'];
        $quantity = $procedure['quantity'];
        $total = $procedure['total_amount'];

        if ($unit && $quantity && $total && abs($unit * $quantity - $total) > 0.01) {
            $findings[] = $this->finding('error', 'aritmetica', 'valorTotal',
                sprintf(
                    'Procedimento %s: %s × %s não fecha com o total de %s.',
                    $label, $this->number($quantity), $this->money($unit), $this->money($total),
                ),
                $number, amountAtRisk: abs($unit * $quantity - $total),
            );
        }

        return $findings;
    }

    private function checkTotals(array $guide): array
    {
        $declared = $guide['declared_total'];
        $sum = array_sum(array_map(fn ($p) => (float) ($p['total_amount'] ?? 0), $guide['procedures']));

        if ($declared === null || abs($declared - $sum) <= 0.01) {
            return [];
        }

        return [$this->finding('error', 'aritmetica', 'valorTotal',
            sprintf(
                'Soma dos procedimentos (%s) não fecha com o valor total declarado na guia (%s).',
                $this->money($sum), $this->money($declared),
            ),
            $guide['claim_number'], amountAtRisk: abs($declared - $sum),
        )];
    }

    /**
     * Histórico desta clínica por código de procedimento: quantas vezes foi
     * faturado, quantas foram glosadas e por qual motivo.
     * É a parte que nenhum validador genérico de TISS consegue fazer.
     */
    private function history(): Collection
    {
        $billed = ClaimItem::query()
            ->selectRaw('procedure_code, count(*) as total')
            ->groupBy('procedure_code')
            ->pluck('total', 'procedure_code');

        return Denial::with('claimItem.claim.payer')
            ->get()
            ->filter(fn (Denial $d) => filled($d->claimItem?->procedure_code))
            ->groupBy(fn (Denial $d) => $d->claimItem->procedure_code)
            ->map(function (Collection $denials, string $code) use ($billed) {
                $total = max((int) ($billed[$code] ?? $denials->count()), $denials->count());

                return [
                    'denied' => $denials->count(),
                    'billed' => $total,
                    'rate' => (int) round($denials->count() / $total * 100),
                    'denials' => $denials,
                ];
            });
    }

    private function checkAgainstHistory(array $guide, Collection $history): array
    {
        $findings = [];

        foreach ($guide['procedures'] as $procedure) {
            $past = $history->get($procedure['code'] ?? '');

            if (! $past) {
                continue;
            }

            if ($past['rate'] < self::MIN_DENIAL_RATE && $past['denied'] < self::ALWAYS_ALERT_FROM) {
                continue;
            }

            // O motivo mais frequente é o que o faturista precisa conferir.
            $reason = $past['denials']
                ->groupBy(fn (Denial $d) => $d->reason_code ?? 'sem código')
                ->sortByDesc(fn (Collection $g) => $g->count())
                ->first()
                ->first();

            $payer = $reason->claimItem?->claim?->payer?->name;

            $findings[] = $this->finding(
                'warning', 'historico', 'codigoProcedimento',
                sprintf(
                    'Glosado %d de %d vez(es) nesta clínica (%d%%)%s. Motivo mais frequente: %s — %s. Confira antes de enviar.',
                    $past['denied'],
                    $past['billed'],
                    $past['rate'],
                    $payer ? ", convênio {$payer}" : '',
                    $reason->reason_code ?? 'não informado',
                    $reason->reason_description ?? 'sem descrição',
                ),
                $guide['claim_number'],
                amountAtRisk: (float) ($procedure['total_amount'] ?? 0),
            );
        }

        return $findings;
    }

    /**
     * Regras que o design system previa e que ficam de fora, com o motivo.
     * A tela mostra esta lista — prometer validação que não acontece é pior
     * do que não validar.
     */
    public function unavailableRules(): array
    {
        return [
            ['rule' => 'Código TUSS/CBHPM válido', 'reason' => 'A Tabela 22 do TUSS é publicação separada e não está entre os insumos do projeto.'],
            ['rule' => 'Período de carência', 'reason' => 'Depende da data de adesão do beneficiário ao plano, que o sistema não recebe.'],
            ['rule' => 'Assinatura do paciente', 'reason' => 'Não é um campo do XML TISS — só existe na via física da guia.'],
            ['rule' => 'Regras contratuais por convênio', 'reason' => 'A coluna `clinic_payers.config` existe e ainda está sem uso.'],
        ];
    }

    public function activeRules(): array
    {
        return [
            'Campos obrigatórios da guia (número, beneficiário, carteira, data)',
            'Data de atendimento no futuro',
            'Guia com número já registrado nesta clínica',
            'Procedimento sem código, descrição, CBO ou valor',
            'Quantidade × valor unitário conferindo com o total do item',
            'Soma dos procedimentos conferindo com o total da guia',
            'Histórico de glosas desta clínica para o mesmo procedimento',
        ];
    }

    private function summarize(array $findings, array $inspected): array
    {
        $errors = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'error'));
        $warnings = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'warning'));

        return [
            'transaction_type' => $inspected['transaction_type'],
            'guide_count' => count($inspected['guides']),
            'procedure_count' => array_sum(array_map(fn ($g) => count($g['procedures']), $inspected['guides'])),
            'error_count' => count($errors),
            'warning_count' => count($warnings),
            // Soma do que está em jogo nos achados — não é "glosa evitada", que
            // seria impossível afirmar: ninguém sabe se a operadora glosaria.
            'amount_at_risk' => round(array_sum(array_column($findings, 'amount_at_risk')), 2),
            'findings' => array_merge($errors, $warnings),
        ];
    }

    private function finding(
        string $severity,
        string $category,
        string $field,
        string $message,
        ?string $claimNumber = null,
        float $amountAtRisk = 0.0,
    ): array {
        return [
            'severity' => $severity,
            'category' => $category,
            'field' => $field,
            'message' => $message,
            'claim_number' => $claimNumber,
            'amount_at_risk' => $amountAtRisk,
        ];
    }

    private function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    private function br(string $isoDate): string
    {
        return implode('/', array_reverse(explode('-', $isoDate)));
    }
}
