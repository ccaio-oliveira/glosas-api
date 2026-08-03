<?php

namespace App\Services\Tiss;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

class TissXmlParser
{
    public function parse(string $xmlContent): ParsedTissFile
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xmlContent);
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('Arquivo XML inválido ou corrompido.');
        }

        $xpath = new DOMXPath($document);

        $ansRegistryCode = $this->firstValue($xpath, null, ['registroANS']);
        $claims = [];

        foreach ($this->nodes($xpath, null, ['dadosGuia', 'guiasSP-SADT', 'guiaConsulta']) as $guiaNode) {
            $claim = $this->parseClaim($xpath, $guiaNode);

            if ($claim !== null) {
                $claims[] = $claim;
            }
        }

        return new ParsedTissFile($ansRegistryCode, $claims);
    }

    private function parseClaim(DOMXPath $xpath, DOMNode $guiaNode): ?array
    {
        $claimNumber = $this->firstValue($xpath, $guiaNode, ['numeroGuiaPrestador', 'numeroGuiaOperadora', 'numeroGuia']);

        if ($claimNumber === null) {
            return null;
        }

        $items = [];

        foreach ($this->nodes($xpath, $guiaNode, ['dadosProcedimento', 'procedimentoExecutado', 'procedimento']) as $node) {
            $item = $this->parseItem($xpath, $node);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'claim_number' => $claimNumber,
            'patient_name' => $this->firstValue($xpath, $guiaNode, ['nomeBeneficiario', 'nomeSegurado', 'nome']) ?? 'Não informado',
            'items' => $items,
        ];
    }

    private function parseItem(DOMXPath $xpath, DOMNode $node): ?array
    {
        $code = $this->firstValue($xpath, $node, ['codigoProcedimento', 'codigoTabela', 'codigo']);

        if ($code === null) {
            return null;
        }

        $billed = $this->firstFloat($xpath, $node, ['valorInformado', 'valorApresentado', 'valorProcedimento', 'valorTotal']);
        $paid = $this->firstFloat($xpath, $node, ['valorLiberado', 'valorPago', 'valorProcessado']);
        $denied = $this->firstFloat($xpath, $node, ['valorGlosa', 'valorGlosado']);

        if ($denied == null && $billed !== null && $paid !== null) {
            $denied = round(max($billed - $paid, 0), 2);
        }

        return [
            'procedure_code' => $code,
            'description' => $this->firstValue($xpath, $node, ['descricaoProcedimento', 'descricao']) ?? 'Procedimento não descrito',
            'billed_amount' => $billed ?? 0.0,
            'paid_amount' => $paid ?? 0.0,
            'denied_amount' => $denied ?? 0.0,
            'denial_reason_code' => $this->firstValue($xpath, $node, ['codigoGlosa', 'motivoGlosa', 'codigoMotivoGlosa'])
        ];
    }

    /** @return iterable<DOMNode> */
    private function nodes(DOMXPath $xpath, ?DOMNode $context, array $names): iterable
    {
        $condition = implode(' or ', array_map(fn ($n) => "local-name()='{$n}'", $names));
        $prefix = $context ? './/' : '//';

        return $xpath->query("{$prefix}*[{$condition}]", $context) ?: [];
    }

    private function firstValue(DOMXPath $xpath, ?DOMNode $context, array $names): ?string
    {
        foreach ($this->nodes($xpath, $context, $names) as $node) {
            $value = trim($node->textContent);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function firstFloat(DOMXPath $xpath, ?DOMNode $context, array $names): ?float
    {
        $value = $this->firstValue($xpath, $context, $names);

        return $value === null ? null : (float) str_replace(',', '.', $value);
    }
}
