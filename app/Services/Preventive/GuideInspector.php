<?php

namespace App\Services\Preventive;

use DateTimeImmutable;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Lê a guia que a clínica vai *enviar* ao convênio (ENVIO_LOTE_GUIAS).
 *
 * Diferente do TissXmlParser, que lê o demonstrativo de retorno e preenche
 * defaults para campo ausente ('Não informado', 0.0): aqui o ausente precisa
 * continuar **null**, porque é justamente o que a validação procura.
 */
class GuideInspector
{
    public function inspect(string $xmlContent): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xmlContent);
        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException(
                'XML inválido ou corrompido'.($libxmlErrors ? ': '.trim($libxmlErrors[0]->message) : '.')
            );
        }

        $xpath = new DOMXPath($document);

        $guides = [];

        foreach ($this->nodes($xpath, null, [
            'guiaSP-SADT', 'guiaSP_SADT', 'guiaConsulta', 'guiaOdontologia',
            'guiaTratamentoOdontologico', 'guiaHonorarios', 'dadosGuia',
        ]) as $node) {
            $guides[] = $this->inspectGuide($xpath, $node);
        }

        return [
            'transaction_type' => $this->value($xpath, null, ['tipoTransacao']),
            'ans_registry_code' => $this->value($xpath, null, ['registroANS']),
            // Assinatura do demonstrativo de *retorno*: só a operadora informa glosa.
            // Se estes campos existem, o arquivo é o do fluxo inverso.
            'has_denial_fields' => $this->value($xpath, null, ['valorGlosa', 'codigoGlosa', 'motivoGlosa']) !== null,
            'guides' => $guides,
        ];
    }

    private function inspectGuide(DOMXPath $xpath, DOMNode $node): array
    {
        $procedures = [];

        foreach ($this->nodes($xpath, $node, [
            'procedimentoExecutado', 'dadosProcedimento', 'procedimentosRealizados', 'procedimento',
        ]) as $procedureNode) {
            $procedures[] = $this->inspectProcedure($xpath, $procedureNode);
        }

        return [
            'claim_number' => $this->value($xpath, $node, ['numeroGuiaPrestador', 'numeroGuia']),
            'patient_name' => $this->value($xpath, $node, ['nomeBeneficiario', 'nomeSegurado']),
            'card_number' => $this->value($xpath, $node, ['numeroCarteira', 'numeroCartaoNacionalSaude']),
            'service_date' => $this->date($xpath, $node, ['dataAtendimento', 'dataExecucao', 'dataRealizacao']),
            'declared_total' => $this->float($xpath, $node, ['valorTotalGeral', 'valorProcedimentos', 'valorTotal']),
            'procedures' => $procedures,
        ];
    }

    private function inspectProcedure(DOMXPath $xpath, DOMNode $node): array
    {
        $unit = $this->float($xpath, $node, ['valorUnitario']);
        $quantity = $this->float($xpath, $node, ['quantidadeExecutada', 'quantidadeSolicitada', 'quantidade']);

        return [
            'code' => $this->value($xpath, $node, ['codigoProcedimento', 'codigoTabela']),
            'description' => $this->value($xpath, $node, ['descricaoProcedimento', 'descricao']),
            'tooth' => $this->value($xpath, $node, ['denteRegiao', 'denteRegiaoClinica', 'codDente']),
            'faces' => array_values(array_filter(
                array_map(
                    fn (DOMNode $f) => trim($f->textContent),
                    iterator_to_array($this->nodes($xpath, $node, ['face', 'codFace']))
                ),
                fn (string $f) => $f !== '',
            )),
            'cbo' => $this->value($xpath, $node, ['cboProfissional', 'CBOS', 'cbos']),
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'total_amount' => $this->float($xpath, $node, ['valorTotal', 'valorInformado', 'valorProcedimento']),
        ];
    }

    /** @return iterable<DOMNode> */
    private function nodes(DOMXPath $xpath, ?DOMNode $context, array $names): iterable
    {
        $condition = implode(' or ', array_map(fn ($n) => "local-name()='{$n}'", $names));

        return $xpath->query(($context ? './/' : '//')."*[{$condition}]", $context) ?: [];
    }

    /**
     * Procura um nome de cada vez, na ordem dada.
     *
     * Consultar todos num XPath só com `or` devolveria em ordem do *documento*,
     * não de preferência: para `['valorTotalGeral', 'valorTotal']`, o valorTotal
     * de um procedimento (que vem antes no XML) venceria o total da guia.
     */
    private function value(DOMXPath $xpath, ?DOMNode $context, array $names): ?string
    {
        foreach ($names as $name) {
            foreach ($this->nodes($xpath, $context, [$name]) as $node) {
                $value = trim($node->textContent);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function float(DOMXPath $xpath, ?DOMNode $context, array $names): ?float
    {
        $value = $this->value($xpath, $context, $names);

        return $value === null ? null : (float) str_replace(',', '.', $value);
    }

    private function date(DOMXPath $xpath, ?DOMNode $context, array $names): ?string
    {
        $raw = $this->value($xpath, $context, $names);

        if ($raw === null) {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'Y-m-d\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, trim($raw));

            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
