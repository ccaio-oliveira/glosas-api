<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedTuss();
    $this->clinic = makeClinic();
    $this->actingAs(makeUser($this->clinic));
});

function denialWithItem(App\Models\Clinic $clinic, string $code, string $description): App\Models\Denial
{
    $denial = makeDenial($clinic, ['procedure_code' => $code]);
    $denial->claimItem->update(['description' => $description]);

    return $denial;
}

it('confirma quando código e descrição batem com a Tabela 22', function () {
    $d = denialWithItem($this->clinic, '85100200', 'Restauração em resina fotopolimerizável 2 faces');

    $this->getJson("/api/denials/{$d->id}")
        ->assertOk()
        ->assertJsonPath('tuss.found', true)
        ->assertJsonPath('tuss.description_matches', true)
        ->assertJsonPath('tuss.official_description', 'Restauração em resina fotopolimerizável 2 faces');
});

it('ignora caixa, acento, pontuação e espaço duplo na comparação', function () {
    // a própria Tabela 22 tem espaço duplo em "fotopolimerizável  2 faces"
    App\Models\TussProcedure::where('code', '85100200')
        ->update(['description' => 'Restauração em resina fotopolimerizável  2 faces']);

    $d = denialWithItem($this->clinic, '85100200', 'RESTAURACAO EM RESINA FOTOPOLIMERIZAVEL - 2 FACES');

    $this->getJson("/api/denials/{$d->id}")->assertJsonPath('tuss.description_matches', true);
});

it('sinaliza descrição diferente da oficial', function () {
    $d = denialWithItem($this->clinic, '84000198', 'Limpeza e polimento (profilaxia)');

    $this->getJson("/api/denials/{$d->id}")
        ->assertJsonPath('tuss.found', true)
        ->assertJsonPath('tuss.description_matches', false)
        ->assertJsonPath('tuss.official_description', 'Profilaxia: polimento coronário');
});

it('sinaliza código que não existe na Tabela 22', function () {
    $d = denialWithItem($this->clinic, '81000234', 'Restauração em resina composta - 2 faces');

    $this->getJson("/api/denials/{$d->id}")
        ->assertJsonPath('tuss.found', false)
        ->assertJsonPath('tuss.official_description', null)
        ->assertJsonPath('tuss.description_matches', null);
});

it('não pesa na listagem — a comparação só vem no detalhe', function () {
    denialWithItem($this->clinic, '85100200', 'x');

    $lista = $this->getJson('/api/denials')->assertOk()->json();

    expect($lista[0])->not->toHaveKey('tuss');
});

it('a fixture de envio da Tabela 22 acusa só o código inventado', function () {
    $xml = file_get_contents(base_path('tests/fixtures/tiss/guia-envio-tuss.xml'));
    $r = app(App\Services\Preventive\PreventiveValidator::class)
        ->validate(app(App\Services\Preventive\GuideInspector::class)->inspect($xml));

    $tuss = collect($r['findings'])->where('category', 'tuss');

    expect($tuss)->toHaveCount(1)
        ->and($tuss->first()['claim_number'])->toBe('4020001')
        ->and($tuss->first()['message'])->toContain('81000234 não existe na Tabela 22');
});
