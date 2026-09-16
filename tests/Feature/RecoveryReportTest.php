<?php

use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Clinic;
use App\Models\Denial;
use App\Models\Payer;
use App\Models\User;
use App\Services\Reports\RecoveryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = Clinic::create(['name' => 'Clínica A', 'cnpj' => '11222333000144']);

    $this->user = User::create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Dra. Teste',
        'email' => 'teste@clinica.test',
        'password' => bcrypt('password'),
        'role' => 'owner',
    ]);

    $this->actingAs($this->user);
});

/** Cria uma glosa completa (convênio → guia → item → glosa). */
function denial(int $clinicId, string $payerName, float $amount, string $status, string $identifiedAt): Denial
{
    $payer = Payer::firstOrCreate(
        ['name' => $payerName],
        ['ans_registry_code' => substr(md5($payerName), 0, 6)],
    );

    $claim = Claim::withoutGlobalScope('clinic')->create([
        'clinic_id' => $clinicId,
        'payer_id' => $payer->id,
        'claim_number' => (string) random_int(100000, 999999),
        'patient_name' => 'Paciente Teste',
        'total_amount' => $amount,
        'status' => 'processed',
    ]);

    $item = ClaimItem::withoutGlobalScope('clinic')->create([
        'clinic_id' => $clinicId,
        'claim_id' => $claim->id,
        'procedure_code' => '81000234',
        'description' => 'Procedimento',
        'billed_amount' => $amount,
        'denied_amount' => $amount,
    ]);

    return Denial::withoutGlobalScope('clinic')->create([
        'clinic_id' => $clinicId,
        'claim_item_id' => $item->id,
        'category' => 'administrative',
        'reason_code' => '1402',
        'amount' => $amount,
        'status' => $status,
        'identified_at' => $identifiedAt,
    ]);
}

it('soma os totais e calcula a taxa de recuperação', function () {
    denial($this->clinic->id, 'Convênio A', 1000, 'recovered', '2026-07-10');
    denial($this->clinic->id, 'Convênio A', 3000, 'new', '2026-07-15');

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
    denial($this->clinic->id, 'Convênio A', 500, 'new', '2026-06-30');
    denial($this->clinic->id, 'Convênio A', 800, 'new', '2026-07-05');

    $r = (new RecoveryReport('2026-07-01', '2026-07-31'))->data();

    expect($r['totals']['denied_amount'])->toBe(800.0)
        ->and($r['totals']['denial_count'])->toBe(1);
});

it('agrupa por convênio com a taxa de cada um', function () {
    denial($this->clinic->id, 'Convênio A', 1000, 'recovered', '2026-07-10');
    denial($this->clinic->id, 'Convênio A', 1000, 'rejected', '2026-07-11');
    denial($this->clinic->id, 'Convênio B', 500, 'new', '2026-07-12');

    $r = (new RecoveryReport())->data();

    // ordenado por valor glosado, do maior para o menor
    expect($r['by_payer'][0]['payer_name'])->toBe('Convênio A')
        ->and($r['by_payer'][0]['recovery_rate'])->toBe(50)
        ->and($r['by_payer'][1]['payer_name'])->toBe('Convênio B')
        ->and($r['by_payer'][1]['recovery_rate'])->toBe(0);
});

it('agrupa por mês em ordem cronológica e rotula em português', function () {
    denial($this->clinic->id, 'Convênio A', 100, 'new', '2026-08-02');
    denial($this->clinic->id, 'Convênio A', 200, 'recovered', '2026-07-20');

    $r = (new RecoveryReport())->data();

    expect($r['by_month'])->toHaveCount(2)
        ->and($r['by_month'][0]['label'])->toBe('jul/2026')
        ->and($r['by_month'][0]['recovered_amount'])->toBe(200.0)
        ->and($r['by_month'][1]['label'])->toBe('ago/2026');
});

it('cobre todos os status, inclusive os que não têm glosa', function () {
    denial($this->clinic->id, 'Convênio A', 100, 'new', '2026-08-02');

    $r = (new RecoveryReport())->data();

    expect($r['by_status'])->toHaveCount(5)
        ->and(collect($r['by_status'])->firstWhere('status', 'new')['share'])->toBe(100)
        ->and(collect($r['by_status'])->firstWhere('status', 'recovered')['count'])->toBe(0);
});

it('não enxerga glosa de outra clínica', function () {
    $other = Clinic::create(['name' => 'Clínica B', 'cnpj' => '99888777000166']);

    denial($this->clinic->id, 'Convênio A', 100, 'new', '2026-08-02');
    denial($other->id, 'Convênio A', 9999, 'new', '2026-08-02');

    $r = (new RecoveryReport())->data();

    expect($r['totals']['denied_amount'])->toBe(100.0);
});

it('expõe o relatório e o PDF para qualquer papel, inclusive viewer', function () {
    denial($this->clinic->id, 'Convênio A', 100, 'recovered', '2026-08-02');

    $viewer = User::create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Visualizador',
        'email' => 'viewer@clinica.test',
        'password' => bcrypt('password'),
        'role' => 'viewer',
    ]);

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
