<?php

namespace App\Services\Appeals;

use App\Models\Denial;

class AppealGenerator
{
    public const CLINICAL_PLACEHOLDER = '[DESCREVA AQUI A JUSTIFICATIVA CLÍNICA DO CASO - sem este trecho o recurso perde força.]';

    public function __construct(
        private AppealTemplateResolver $resolver
    ){}

    public function generate(Denial $denial, ?string $clinicalInput = null): GeneratedAppeal
    {
        $template = $this->resolver->resolve($denial);

        if (!$template) {
            return new GeneratedAppeal(null, null, 'needs_ai');
        }

        $attachments = $template->required_attachments ?? [];

        return new GeneratedAppeal(
            text: $this->render($template->body, $denial, $template, $clinicalInput, $attachments),
            template: $template,
            source: 'template_'.$template->scope,
            attachments: $attachments,
            requiresClinicalInput: $template->requires_clinical_input && blank($clinicalInput),
            missingLegalBasis: blank($template->legal_basis),
        );
    }

    private function render(string $body, Denial $denial, $template, ?string $clinicalInput, array $attachments): string
    {
        $item = $denial->claimItem;
        $claim = $item?->claim;
        $clinic = $denial->clinic;

        $clinical = filled($clinicalInput) ? trim($clinicalInput) : self::CLINICAL_PLACEHOLDER;

        $replacements = [
            '{{clinic_name}}' => $clinic?->name ?? '',
            '{{clinic_cnpj}}' => $clinic?->cnpj ?? '',
            '{{clinic_cro}}' => $clinic?->cro ?? '(CRO não cadastrado)',
            '{{payer_name}}' => $claim?->payer?->name ?? '',
            '{{claim_number}}' => $claim?->claim_number ?? '',
            '{{patient_name}}' => $claim?->patient_name ?? '',
            '{{procedure_code}}' => $item?->procedure_code ?? '',
            '{{procedure_description}}' => $item?->description ?? '',
            '{{billed_amount}}' => $this->money($item?->billed_amount),
            '{{denied_amount}}' => $this->money($denial->amount),
            '{{reason_code}}' => $denial->reason_code ?? 'não informado',
            '{{reason_description}}' => $denial->reason_description ?? 'não informado',
            '{{identified_at}}' => $denial->identified_at?->format('d/m/Y') ?? '',
            '{{today}}' => now()->format('d/m/Y'),
            '{{clinical_section}}' => $template->requires_clinical_input ? $clinical : '',
            '{{legal_basis}}' => $template->legal_basis ?: '[FUNDAMENTAÇÃO LEGAL NÃO CADASTRADA NESTE MODELO - preencha antes de enviar.]',
            '{{attachments}}' => $attachments ? collect($attachments)->map(fn ($a) => "• {$a}")->implode("\n") : "• Guia TISS original",
        ];

        $rendered = strtr($body, $replacements);

        // remove linhas em branco triplicadas deixadas por placeholders vazios
        return trim(preg_replace("/\n{3,}/", "\n\n", $rendered));
    }

    private function money(?float $value): string
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }
}
