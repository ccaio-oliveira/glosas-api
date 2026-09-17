<?php

use App\Services\Preventive\GuideInspector;
use App\Services\Preventive\PreventiveValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = makeClinic();
    $this->actingAs(makeUser($this->clinic));
});

function analyze(string $xml): array
{
    return app(PreventiveValidator::class)->validate(app(GuideInspector::class)->inspect($xml));
}

function fixtureXml(string $name = 'guia-envio-com-problemas.xml'): string
{
    return file_get_contents(base_path("tests/fixtures/tiss/{$name}"));
}

/** Guia mínima válida, para exercitar uma regra por vez. */
function guideXml(string $inner, string $total = '100.00'): string
{
    return <<<XML
    <ans:mensagemTISS xmlns:ans="http://www.ans.gov.br/padroes/tiss/schemas">
      <ans:cabecalho><ans:identificacaoTransacao><ans:tipoTransacao>ENVIO_LOTE_GUIAS</ans:tipoTransacao></ans:identificacaoTransacao></ans:cabecalho>
      <ans:loteGuias>
        <ans:guiaOdontologia>
          <ans:numeroGuiaPrestador>5001</ans:numeroGuiaPrestador>
          <ans:dadosBeneficiario>
            <ans:numeroCarteira>111</ans:numeroCarteira>
            <ans:nomeBeneficiario>Paciente Teste</ans:nomeBeneficiario>
          </ans:dadosBeneficiario>
          <ans:dataAtendimento>2026-09-01</ans:dataAtendimento>
          <ans:procedimentosExecutados>{$inner}</ans:procedimentosExecutados>
          <ans:valorTotalGeral>{$total}</ans:valorTotalGeral>
        </ans:guiaOdontologia>
      </ans:loteGuias>
    </ans:mensagemTISS>
    XML;
}

const OK_PROCEDURE = '<ans:procedimentoExecutado>
    <ans:codigoProcedimento>81000450</ans:codigoProcedimento>
    <ans:descricaoProcedimento>Profilaxia</ans:descricaoProcedimento>
    <ans:cboProfissional>223208</ans:cboProfissional>
    <ans:quantidadeExecutada>1</ans:quantidadeExecutada>
    <ans:valorUnitario>100.00</ans:valorUnitario>
    <ans:valorTotal>100.00</ans:valorTotal>
</ans:procedimentoExecutado>';

/** Mensagens de achado de uma categoria/severidade. */
function messages(array $result, ?string $category = null, ?string $severity = null): array
{
    return collect($result['findings'])
        ->when($category, fn ($c) => $c->where('category', $category))
        ->when($severity, fn ($c) => $c->where('severity', $severity))
        ->pluck('message')->all();
}

it('não acusa nada numa guia bem formada', function () {
    $r = analyze(guideXml(OK_PROCEDURE));

    expect($r['error_count'])->toBe(0)
        ->and($r['warning_count'])->toBe(0)
        ->and($r['guide_count'])->toBe(1)
        ->and($r['procedure_count'])->toBe(1);
});

it('reconhece o tipo de transação', function () {
    expect(analyze(guideXml(OK_PROCEDURE))['transaction_type'])->toBe('ENVIO_LOTE_GUIAS');
});

it('recusa o demonstrativo de retorno, que é o arquivo errado para esta tela', function () {
    // é estruturalmente parecido com uma guia de envio e passaria limpo:
    // "nenhum problema" num arquivo que sequer é o certo enganaria o faturista
    $r = analyze(fixtureXml('demonstrativo-valido.xml'));

    expect($r['error_count'])->toBe(1)
        ->and(messages($r)[0])->toContain('demonstrativo de retorno da operadora')
        ->and(messages($r)[0])->toContain('Upload TISS');
});

it('recusa também pelo tipo de transação declarado no cabeçalho', function () {
    $xml = str_replace('ENVIO_LOTE_GUIAS', 'DEMONSTRATIVO_ANALISE_CONTA', guideXml(OK_PROCEDURE));

    expect(messages(analyze($xml))[0])->toContain('demonstrativo de retorno da operadora');
});

it('estoura em XML malformado em vez de analisar lixo', function () {
    expect(fn () => analyze('<tiss><naoFecha>'))->toThrow(RuntimeException::class);
});

it('acusa campo obrigatório ausente', function (string $remove, string $esperado) {
    $xml = str_replace($remove, '', guideXml(OK_PROCEDURE));

    expect(messages(analyze($xml), 'estrutura', 'error'))
        ->toContain($esperado);
})->with([
    ['<ans:nomeBeneficiario>Paciente Teste</ans:nomeBeneficiario>', 'Nome do beneficiário ausente.'],
    ['<ans:numeroCarteira>111</ans:numeroCarteira>', 'Número da carteira do beneficiário ausente.'],
    ['<ans:dataAtendimento>2026-09-01</ans:dataAtendimento>', 'Data do atendimento ausente ou em formato não reconhecido.'],
]);

it('acusa data de atendimento no futuro', function () {
    $futuro = now()->addYear()->toDateString();
    $xml = str_replace('2026-09-01', $futuro, guideXml(OK_PROCEDURE));

    expect(messages(analyze($xml), 'estrutura', 'error'))
        ->toContain('Data do atendimento está no futuro ('.now()->addYear()->format('d/m/Y').').');
});

it('acusa quantidade x unitário que não fecha com o total do item', function () {
    $xml = guideXml(str_replace('<ans:quantidadeExecutada>1<', '<ans:quantidadeExecutada>3<', OK_PROCEDURE));

    expect(messages(analyze($xml), 'aritmetica'))
        ->toContain('Procedimento 81000450: 3 × R$ 100,00 não fecha com o total de R$ 100,00.');
});

it('acusa soma dos procedimentos diferente do total da guia', function () {
    // o total da guia vem depois dos procedimentos no XML: a busca precisa
    // respeitar a ordem de preferência dos nomes, não a ordem do documento
    $r = analyze(guideXml(OK_PROCEDURE, total: '250.00'));

    expect(messages($r, 'aritmetica'))
        ->toContain('Soma dos procedimentos (R$ 100,00) não fecha com o valor total declarado na guia (R$ 250,00).');
});

it('avisa sobre guia com número já registrado', function () {
    makeDenial($this->clinic, ['amount' => 100]);
    $numero = App\Models\Claim::withoutGlobalScope('clinic')->first()->claim_number;

    $xml = str_replace('5001', $numero, guideXml(OK_PROCEDURE));

    expect(messages(analyze($xml), 'duplicidade'))
        ->toContain("Já existe uma guia {$numero} registrada nesta clínica. Confirme que não é reenvio.");
});

it('avisa quando o procedimento tem histórico de glosa nesta clínica', function () {
    // duas glosas do mesmo código: taxa de 100%, acima do limiar
    makeDenial($this->clinic, ['amount' => 180, 'reason_code' => '1402',
        'reason_description' => 'Procedimento não autorizado']);
    makeDenial($this->clinic, ['amount' => 180, 'reason_code' => '1402',
        'reason_description' => 'Procedimento não autorizado']);

    $xml = guideXml(str_replace('81000450', '81000234', OK_PROCEDURE));
    $mensagens = messages(analyze($xml), 'historico');

    expect($mensagens)->toHaveCount(1)
        ->and($mensagens[0])->toContain('Glosado 2 de 2 vez(es)')
        ->and($mensagens[0])->toContain('1402')
        ->and($mensagens[0])->toContain('Procedimento não autorizado');
});

it('não avisa quando a taxa de glosa é baixa — evita fadiga de alerta', function () {
    // 1 glosa em 10 faturamentos = 10%, abaixo do limiar de 25%
    makeDenial($this->clinic, ['amount' => 100, 'reason_code' => '1402']);

    $item = App\Models\ClaimItem::withoutGlobalScope('clinic')->first();

    foreach (range(1, 9) as $i) {
        App\Models\ClaimItem::withoutGlobalScope('clinic')->create([
            'clinic_id' => $this->clinic->id,
            'claim_id' => $item->claim_id,
            'procedure_code' => $item->procedure_code,
            'description' => 'Repetido',
            'billed_amount' => 100,
        ]);
    }

    $xml = guideXml(str_replace('81000450', $item->procedure_code, OK_PROCEDURE));

    expect(messages(analyze($xml), 'historico'))->toBeEmpty();
});

it('não enxerga o histórico de outra clínica', function () {
    $outra = makeClinic('Outra');
    makeDenial($outra, ['amount' => 180, 'reason_code' => '1402']);
    makeDenial($outra, ['amount' => 180, 'reason_code' => '1402']);

    $xml = guideXml(str_replace('81000450', '81000234', OK_PROCEDURE));

    expect(messages(analyze($xml), 'historico'))->toBeEmpty();
});

it('soma o valor em jogo dos achados', function () {
    $r = analyze(guideXml(OK_PROCEDURE, total: '250.00'));

    // a diferença de 150,00 entre a soma e o total declarado
    expect($r['amount_at_risk'])->toBe(150.0);
});

it('produz na fixture exatamente os problemas que ela documenta', function () {
    $r = analyze(fixtureXml());

    expect($r['guide_count'])->toBe(4)
        ->and($r['error_count'])->toBe(5)
        ->and(messages($r, 'estrutura', 'error'))->toContain('Nome do beneficiário ausente.')
        ->and(messages($r, 'aritmetica'))->toHaveCount(2);
});

it('expõe as regras ativas e as que ainda não existem, com o motivo', function () {
    $r = $this->getJson('/api/preventive/rules')->assertOk()->json();

    expect($r['active'])->not->toBeEmpty()
        ->and($r['unavailable'])->not->toBeEmpty()
        ->and(collect($r['unavailable'])->pluck('rule'))->toContain('Código TUSS/CBHPM válido')
        ->and(collect($r['unavailable'])->every(fn ($u) => filled($u['reason'])))->toBeTrue();
});

it('devolve 422 em XML inválido, sem virar incidente no painel de erros', function () {
    $this->postJson('/api/preventive/validate', ['xml' => '<quebrado'])
        ->assertStatus(422);

    expect(App\Models\ErrorLog::count())->toBe(0);
});

it('deixa o viewer analisar — é leitura, não escreve nada', function () {
    $this->actingAs(makeUser($this->clinic, 'viewer'))
        ->postJson('/api/preventive/validate', ['xml' => guideXml(OK_PROCEDURE)])
        ->assertOk()
        ->assertJsonPath('error_count', 0);
});
