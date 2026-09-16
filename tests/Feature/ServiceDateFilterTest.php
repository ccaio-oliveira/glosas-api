<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = makeClinic();
    $this->actingAs(makeUser($this->clinic));

    makeDenial($this->clinic, ['amount' => 100, 'service_date' => '2026-05-10', 'patient_name' => 'Maria Souza']);
    makeDenial($this->clinic, ['amount' => 200, 'service_date' => '2026-07-22', 'patient_name' => 'João Lima']);
    makeDenial($this->clinic, ['amount' => 300, 'service_date' => null, 'patient_name' => 'Sem Data']);
});

it('devolve a data do atendimento na listagem de glosas', function () {
    $glosas = $this->getJson('/api/denials')->assertOk()->json();

    expect(collect($glosas)->firstWhere('patient_name', 'Maria Souza')['service_date'])->toBe('2026-05-10')
        ->and(collect($glosas)->firstWhere('patient_name', 'Sem Data')['service_date'])->toBeNull();
});

it('filtra glosas pelo período de atendimento', function () {
    $julho = $this->getJson('/api/denials?from=2026-07-01&to=2026-07-31')->assertOk()->json();

    expect($julho)->toHaveCount(1)
        ->and($julho[0]['patient_name'])->toBe('João Lima');
});

it('aceita só a data inicial ou só a final', function () {
    expect($this->getJson('/api/denials?from=2026-06-01')->json())->toHaveCount(1)
        ->and($this->getJson('/api/denials?to=2026-06-01')->json())->toHaveCount(1);
});

it('combina período com os filtros que já existiam', function () {
    $r = $this->getJson('/api/denials?from=2026-01-01&to=2026-12-31&search=João')->assertOk()->json();

    expect($r)->toHaveCount(1)
        ->and($r[0]['patient_name'])->toBe('João Lima');
});

it('filtra e busca guias pela data do atendimento', function () {
    expect($this->getJson('/api/claims?from=2026-07-01')->assertOk()->json())->toHaveCount(1)
        ->and($this->getJson('/api/claims?search=Maria')->assertOk()->json())->toHaveCount(1);
});

it('ordena guias pelo atendimento mais recente, com as sem data no fim', function () {
    $guias = $this->getJson('/api/claims')->assertOk()->json();

    expect(array_column($guias, 'patient_name'))->toBe(['João Lima', 'Maria Souza', 'Sem Data']);
});

it('recusa data de atendimento no futuro ao lançar guia à mão', function () {
    $payerId = App\Models\Claim::withoutGlobalScope('clinic')->first()->payer_id;

    $this->postJson('/api/claims', [
        'payer_id' => $payerId,
        'claim_number' => '999',
        'patient_name' => 'Teste',
        'service_date' => now()->addDay()->toDateString(),
        'total_amount' => 100,
    ])->assertStatus(422)->assertJsonValidationErrors('service_date');
});

it('aceita guia lançada à mão sem data de atendimento', function () {
    $payerId = App\Models\Claim::withoutGlobalScope('clinic')->first()->payer_id;

    $this->postJson('/api/claims', [
        'payer_id' => $payerId,
        'claim_number' => '998',
        'patient_name' => 'Teste',
        'total_amount' => 100,
    ])->assertCreated();
});
