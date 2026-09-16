<?php

use App\Models\Appeal;
use App\Models\AuditLog;
use App\Models\Denial;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = makeClinic();
    $this->user = makeUser($this->clinic);
    $this->actingAs($this->user);
});

function appealFor(Denial $denial, array $attributes = []): Appeal
{
    return Appeal::create(array_merge([
        'clinic_id' => $denial->clinic_id,
        'denial_id' => $denial->id,
        'ai_generated_text' => 'Texto do recurso.',
        'generation_source' => 'template_code',
        'status' => 'draft',
    ], $attributes));
}

it('sincroniza a glosa ao mudar o status do recurso', function (string $appealStatus, string $expectedDenialStatus) {
    $denial = makeDenial($this->clinic, ['status' => 'new']);
    $appeal = appealFor($denial);

    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => $appealStatus])->assertOk();

    expect($denial->fresh()->status)->toBe($expectedDenialStatus);
})->with([
    ['submitted', 'appealed'],
    ['under_review', 'appealed'],
    ['accepted', 'recovered'],
    ['rejected', 'rejected'],
]);

it('não mexe na glosa ao voltar o recurso para rascunho', function () {
    $denial = makeDenial($this->clinic, ['status' => 'appealed']);
    $appeal = appealFor($denial, ['status' => 'submitted']);

    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'draft'])->assertOk();

    // rascunho não tem equivalente do lado da glosa — deixar como está é o certo
    expect($denial->fresh()->status)->toBe('appealed');
});

it('carimba responded_at ao chegar num status final', function () {
    $denial = makeDenial($this->clinic);
    $appeal = appealFor($denial, ['status' => 'submitted', 'submitted_at' => now()->subDays(10)]);

    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'accepted'])->assertOk();

    expect($appeal->fresh()->responded_at)->not->toBeNull();
});

it('preserva a data da resposta ao trocar entre dois status finais', function () {
    $denial = makeDenial($this->clinic);
    $appeal = appealFor($denial, ['status' => 'accepted', 'responded_at' => '2026-07-01 10:00:00']);

    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'rejected'])->assertOk();

    // a operadora respondeu uma vez só; corrigir a leitura não move a data
    expect($appeal->fresh()->responded_at->format('Y-m-d'))->toBe('2026-07-01');
});

it('limpa responded_at ao voltar para um status não final', function () {
    $denial = makeDenial($this->clinic);
    $appeal = appealFor($denial, ['status' => 'accepted', 'responded_at' => now()]);

    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'under_review'])->assertOk();

    expect($appeal->fresh()->responded_at)->toBeNull();
});

it('registra a mudança na auditoria, com a cascata explícita', function () {
    $denial = makeDenial($this->clinic);
    $appeal = appealFor($denial, ['status' => 'submitted']);

    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'accepted'])->assertOk();

    $log = AuditLog::where('action', 'appeal.status_changed')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->denial_id)->toBe($denial->id)
        ->and($log->summary)->toContain('Enviado')
        ->and($log->summary)->toContain('Recuperado')
        ->and($log->summary)->toContain('glosa passou a Recuperada')
        ->and($log->user_name)->toBe($this->user->name);
});

it('não registra auditoria quando o status não muda', function () {
    $denial = makeDenial($this->clinic);
    $appeal = appealFor($denial, ['status' => 'submitted']);

    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'submitted'])->assertOk();

    expect(AuditLog::where('action', 'appeal.status_changed')->count())->toBe(0);
});

it('recusa status fora do ciclo', function () {
    $appeal = appealFor(makeDenial($this->clinic));

    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'aprovado'])
        ->assertStatus(422);
});

it('bloqueia o viewer de mudar status', function () {
    $appeal = appealFor(makeDenial($this->clinic));

    $this->actingAs(makeUser($this->clinic, 'viewer'))
        ->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'accepted'])
        ->assertForbidden();
});

it('não deixa mexer em recurso de outra clínica', function () {
    $other = makeClinic('Outra');
    $appeal = appealFor(makeDenial($other));

    // o global scope some com o registro; o binding de rota devolve 404
    $this->putJson("/api/appeals/{$appeal->id}/status", ['status' => 'accepted'])
        ->assertNotFound();
});

it('o resumo de Recursos bate com os status gravados', function () {
    $d1 = makeDenial($this->clinic, ['amount' => 1000]);
    $d2 = makeDenial($this->clinic, ['amount' => 500]);
    $d3 = makeDenial($this->clinic, ['amount' => 300]);

    appealFor($d1, ['status' => 'submitted', 'submitted_at' => now()->subDays(20)]);
    appealFor($d2, ['status' => 'under_review', 'submitted_at' => now()->subDays(10)]);
    appealFor($d3, ['status' => 'accepted', 'submitted_at' => now()->subDays(30), 'responded_at' => now()->subDays(20)]);

    $summary = $this->getJson('/api/appeals/summary')->assertOk()->json();

    // "aguardando resposta" = enviado + em análise
    expect($summary['awaiting_count'])->toBe(2)
        ->and($summary['awaiting_amount'])->toEqual(1500)
        ->and($summary['recovered_amount'])->toEqual(300)
        ->and($summary['success_rate'])->toBe(100)   // só 1 respondeu, e foi aceito
        ->and($summary['avg_response_days'])->toBe(10);
});

it('não reporta taxa de êxito enquanto ninguém respondeu', function () {
    appealFor(makeDenial($this->clinic), ['status' => 'submitted']);

    $summary = $this->getJson('/api/appeals/summary')->assertOk()->json();

    expect($summary['success_rate'])->toBeNull()
        ->and($summary['avg_response_days'])->toBeNull();
});
