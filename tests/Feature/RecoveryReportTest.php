<?php

use App\Services\Reports\RecoveryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = makeClinic('Clínica A');
    $this->actingAs(makeUser($this->clinic));
});

it('soma os totais e calcula a taxa de recuperação', function () {
    makeDenial($this->clinic, ['amount' => 1000, 'status' => 'recovered', 'identified_at' => '2026-07-10'], 'Convênio A');
    makeDenial($this->clinic, ['amount' => 3000, 'status' => 'new', 'identified_at' => '2026-07-15'], 'Convênio A');

    $r = (new RecoveryReport())->data();

    expect($r['totals']['denied_amount'])->toBe(4000.0)
        ->and($r['totals']['recovered_amount'])->toBe(1000.0)
        ->and($r['totals']['recovery_rate'])->toBe(25)
        ->and($r['totals']['denial_count'])->toBe(2);
});

it('devolve taxa null quando não há glosa, em vez de zero', function () {
    $r = (new RecoveryReport())->data();

    // 0% sugeriria fracasso; null diz "não há dado"
    expect($r['totals']['recovery_rate'])->toBeNull()
        ->and($r['totals']['denied_amount'])->toBe(0.0);
});

it('respeita o período pedido', function () {
    makeDenial($this->clinic, ['amount' => 500, 'status' => 'new', 'identified_at' => '2026-06-30'], 'Convênio A');
    makeDenial($this->clinic, ['amount' => 800, 'status' => 'new', 'identified_at' => '2026-07-05'], 'Convênio A');

    $r = (new RecoveryReport('2026-07-01', '2026-07-31'))->data();

    expect($r['totals']['denied_amount'])->toBe(800.0)
        ->and($r['totals']['denial_count'])->toBe(1);
});

it('agrupa por convênio com a taxa de cada um', function () {
    makeDenial($this->clinic, ['amount' => 1000, 'status' => 'recovered', 'identified_at' => '2026-07-10'], 'Convênio A');
    makeDenial($this->clinic, ['amount' => 1000, 'status' => 'rejected', 'identified_at' => '2026-07-11'], 'Convênio A');
    makeDenial($this->clinic, ['amount' => 500, 'status' => 'new', 'identified_at' => '2026-07-12'], 'Convênio B');

    $r = (new RecoveryReport())->data();

    // ordenado por valor glosado, do maior para o menor
    expect($r['by_payer'][0]['payer_name'])->toBe('Convênio A')
        ->and($r['by_payer'][0]['recovery_rate'])->toBe(50)
        ->and($r['by_payer'][1]['payer_name'])->toBe('Convênio B')
        ->and($r['by_payer'][1]['recovery_rate'])->toBe(0);
});

it('agrupa por mês em ordem cronológica e rotula em português', function () {
    makeDenial($this->clinic, ['amount' => 100, 'status' => 'new', 'identified_at' => '2026-08-02'], 'Convênio A');
    makeDenial($this->clinic, ['amount' => 200, 'status' => 'recovered', 'identified_at' => '2026-07-20'], 'Convênio A');

    $r = (new RecoveryReport())->data();

    expect($r['by_month'])->toHaveCount(2)
        ->and($r['by_month'][0]['label'])->toBe('jul/2026')
        ->and($r['by_month'][0]['recovered_amount'])->toBe(200.0)
        ->and($r['by_month'][1]['label'])->toBe('ago/2026');
});

it('cobre todos os status, inclusive os que não têm glosa', function () {
    makeDenial($this->clinic, ['amount' => 100, 'status' => 'new', 'identified_at' => '2026-08-02'], 'Convênio A');

    $r = (new RecoveryReport())->data();

    expect($r['by_status'])->toHaveCount(5)
        ->and(collect($r['by_status'])->firstWhere('status', 'new')['share'])->toBe(100)
        ->and(collect($r['by_status'])->firstWhere('status', 'recovered')['count'])->toBe(0);
});

it('não enxerga glosa de outra clínica', function () {
    $other = makeClinic('Clínica B');

    makeDenial($this->clinic, ['amount' => 100, 'status' => 'new', 'identified_at' => '2026-08-02'], 'Convênio A');
    makeDenial($other, ['amount' => 9999, 'status' => 'new', 'identified_at' => '2026-08-02'], 'Convênio A');

    $r = (new RecoveryReport())->data();

    expect($r['totals']['denied_amount'])->toBe(100.0);
});

it('expõe o relatório e o PDF para qualquer papel, inclusive viewer', function () {
    makeDenial($this->clinic, ['amount' => 100, 'status' => 'recovered', 'identified_at' => '2026-08-02'], 'Convênio A');

    $viewer = makeUser($this->clinic, 'viewer');

    $this->actingAs($viewer)
        ->getJson('/api/reports/recovery')
        ->assertOk()
        ->assertJsonPath('totals.recovered_amount', 100);

    $pdf = $this->actingAs($viewer)->get('/api/reports/recovery/pdf');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

it('recusa período com data final anterior à inicial', function () {
    $this->getJson('/api/reports/recovery?from=2026-08-01&to=2026-07-01')
        ->assertStatus(422);
});
