<?php

use App\Models\AuditLog;
use App\Models\ClinicPayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
 * Cadastro do prazo de recurso no vínculo clínica-convênio: fonte obrigatória,
 * metadados gerados no servidor e auditoria só quando prazo ou fonte mudam.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-18 10:00:00');

    $this->clinic = makeClinic();
    $this->user = makeUser($this->clinic, 'biller');
    makeDenial($this->clinic);
    $this->link = ClinicPayer::withoutGlobalScope('clinic')->where('clinic_id', $this->clinic->id)->firstOrFail();
    $this->actingAs($this->user);
});

afterEach(fn () => Carbon::setTestNow());

function putDeadline(int $linkId, array $deadline)
{
    return test()->putJson("/api/payers/{$linkId}", ['name' => 'Convênio Teste', 'integration_type' => 'manual'] + $deadline);
}

function deadlineAudits()
{
    return AuditLog::withoutGlobalScope('clinic')->where('action', 'payer.deadline_updated')->orderBy('id')->get();
}

it('exige a fonte quando o prazo é informado', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appeal_deadline_source' => 'Informe a fonte do prazo (ex.: contrato e cláusula).']);

    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => '   '])
        ->assertStatus(422)->assertJsonValidationErrors('appeal_deadline_source');
});

it('limpa prazo e fonte juntos quando o prazo vem nulo', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato, cláusula 4'])->assertOk();

    putDeadline($this->link->id, ['appeal_deadline_days' => null, 'appeal_deadline_source' => 'Contrato, cláusula 4'])
        ->assertOk()
        ->assertJsonPath('appeal_deadline_days', null)
        ->assertJsonPath('appeal_deadline_source', null);

    expect($this->link->fresh()->config)->toMatchArray(['appeal_deadline_days' => null, 'appeal_deadline_source' => null]);
});

it('recusa caractere de controle na fonte', function (string $source) {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => $source])
        ->assertStatus(422)->assertJsonValidationErrors('appeal_deadline_source');
})->with([
    'quebra de linha' => "Contrato\ncláusula 4",
    'bell' => "Contrato\x07",
    'DEL' => "Contrato\x7F",
    'inversão bidi U+202E' => "Contrato \u{202E}4 asuálc",
    // No meio: nas pontas o TrimStrings já remove antes da validação.
    'espaço de largura zero' => "Contra\u{200B}to",
]);

it('aceita acentos e pontuação comuns na fonte', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato nº 12/2025 — cláusula 7.3, §2º'])
        ->assertOk()->assertJsonPath('appeal_deadline_source', 'Contrato nº 12/2025 — cláusula 7.3, §2º');
});

it('registra o prazo que existia ao excluir o vínculo', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();

    $this->deleteJson("/api/payers/{$this->link->id}")->assertNoContent();

    $audit = AuditLog::withoutGlobalScope('clinic')->where('action', 'payer.unlinked')->sole();

    expect($audit->changes)->toBe([
        'appeal_deadline_days' => ['from' => 30, 'to' => null],
        'appeal_deadline_source' => ['from' => 'Contrato A', 'to' => null],
    ])->and($audit->clinic_id)->toBe($this->clinic->id);
});

it('não audita excluir vínculo que não tinha prazo', function () {
    $this->deleteJson("/api/payers/{$this->link->id}")->assertNoContent();

    expect(AuditLog::withoutGlobalScope('clinic')->where('action', 'payer.unlinked')->count())->toBe(0);
});

it('recusa prazo que não é inteiro', function (mixed $days) {
    putDeadline($this->link->id, ['appeal_deadline_days' => $days, 'appeal_deadline_source' => 'Contrato'])
        ->assertStatus(422)->assertJsonValidationErrors('appeal_deadline_days');
})->with(['texto' => 'trinta', 'decimal' => '1.5']);

it('ignora metadados enviados pelo cliente e grava o usuário autenticado', function () {
    putDeadline($this->link->id, [
        'appeal_deadline_days' => 30,
        'appeal_deadline_source' => 'Contrato, cláusula 4',
        'appeal_deadline_updated_at' => '2000-01-01T00:00:00+00:00',
        'appeal_deadline_updated_by_user_id' => 999,
        'appeal_deadline_updated_by' => 'Outra pessoa',
    ])
        ->assertOk()
        ->assertJsonPath('appeal_deadline_updated_by', $this->user->name)
        ->assertJsonPath('appeal_deadline_updated_at', '2026-09-18T07:00:00-03:00');

    expect($this->link->fresh()->config)->toMatchArray([
        'appeal_deadline_updated_by_user_id' => $this->user->id,
        'appeal_deadline_updated_at' => '2026-09-18T07:00:00-03:00',
    ])->and($this->link->fresh()->config)->not->toHaveKey('appeal_deadline_updated_by');
});

it('audita a mudança de prazo só com prazo e fonte', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();
    putDeadline($this->link->id, ['appeal_deadline_days' => 45, 'appeal_deadline_source' => 'Aditivo B'])->assertOk();

    $audits = deadlineAudits();

    expect($audits)->toHaveCount(2)
        ->and($audits[1]->summary)->toBe('Prazo de recurso de Convênio Teste: 30 → 45 dias')
        ->and($audits[1]->changes)->toBe([
            'appeal_deadline_days' => ['from' => 30, 'to' => 45],
            'appeal_deadline_source' => ['from' => 'Contrato A', 'to' => 'Aditivo B'],
        ])
        ->and($audits[1]->clinic_id)->toBe($this->clinic->id)
        ->and($audits[1]->auditable_type)->toBe('ClinicPayer')
        ->and($audits[1]->auditable_id)->toBe($this->link->id)
        ->and($audits[1]->user_id)->toBe($this->user->id);
});

it('audita mudança só da fonte', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A, cláusula 2'])->assertOk();

    expect(deadlineAudits())->toHaveCount(2)
        ->and(deadlineAudits()[1]->changes['appeal_deadline_source'])->toBe(['from' => 'Contrato A', 'to' => 'Contrato A, cláusula 2']);
});

it('não audita nem mexe na data quando nada mudou', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();
    $before = $this->link->fresh()->config['appeal_deadline_updated_at'];

    Carbon::setTestNow('2026-09-20 15:00:00');
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])
        ->assertOk()
        ->assertJsonPath('appeal_deadline_updated_at', $before);

    // Sem a chave do prazo no corpo, o prazo não é tocado.
    putDeadline($this->link->id, [])->assertOk()->assertJsonPath('appeal_deadline_days', 30);

    expect(deadlineAudits())->toHaveCount(1)
        ->and($this->link->fresh()->config['appeal_deadline_updated_at'])->toBe($before);
});

it('não audita salvar sem prazo um convênio que nunca teve prazo', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => null])->assertOk();

    expect(deadlineAudits())->toHaveCount(0)
        ->and($this->link->fresh()->config['appeal_deadline_updated_at'] ?? null)->toBeNull();
});

it('audita o prazo de um vínculo novo a partir de nulo', function () {
    $this->postJson('/api/payers', [
        'name' => 'Convênio Novo', 'integration_type' => 'manual',
        'appeal_deadline_days' => 60, 'appeal_deadline_source' => 'Contrato 2026',
    ])->assertOk()
        ->assertJsonPath('appeal_deadline_days', 60)
        ->assertJsonPath('appeal_deadline_source', 'Contrato 2026')
        ->assertJsonPath('appeal_deadline_updated_by', $this->user->name);

    $audit = deadlineAudits()->sole();

    expect($audit->summary)->toBe('Prazo de recurso de Convênio Novo: não configurado → 60 dias')
        ->and($audit->changes)->toBe([
            'appeal_deadline_days' => ['from' => null, 'to' => 60],
            'appeal_deadline_source' => ['from' => null, 'to' => 'Contrato 2026'],
        ])
        ->and($audit->clinic_id)->toBe($this->clinic->id);
});

it('não apaga o prazo ao cadastrar de novo um convênio já vinculado sem prazo', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();

    // Como o formulário envia: prazo vazio vira null.
    $this->postJson('/api/payers', [
        'name' => 'convênio teste', 'integration_type' => 'manual',
        'appeal_deadline_days' => '', 'appeal_deadline_source' => '',
    ])->assertOk()
        ->assertJsonPath('id', $this->link->id)
        ->assertJsonPath('appeal_deadline_days', 30)
        ->assertJsonPath('appeal_deadline_source', 'Contrato A');

    expect(deadlineAudits())->toHaveCount(1);
});

it('não audita vínculo novo sem prazo', function () {
    $this->postJson('/api/payers', ['name' => 'Convênio Novo', 'integration_type' => 'manual'])->assertOk();

    expect(deadlineAudits())->toHaveCount(0);
});

it('mostra quem atualizou o prazo só a quem opera', function (string $role, bool $seesName) {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();

    $payer = collect($this->actingAs(makeUser($this->clinic, $role))->getJson('/api/payers')->assertOk()->json())->sole();

    expect($payer)->toMatchArray([
        'appeal_deadline_days' => 30,
        'appeal_deadline_source' => 'Contrato A',
        'appeal_deadline_updated_at' => '2026-09-18T07:00:00-03:00',
        'appeal_deadline_updated_by' => $seesName ? $this->user->name : null,
    ]);
})->with([
    'viewer' => ['viewer', false],
    'biller' => ['biller', true],
    'owner' => ['owner', true],
]);

it('devolve autor nulo quando o usuário não existe mais', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();
    $owner = makeUser($this->clinic, 'owner');
    $this->user->delete();

    $payer = collect($this->actingAs($owner)->getJson('/api/payers')->json())->sole();

    expect($payer['appeal_deadline_updated_by'])->toBeNull()
        ->and($payer['appeal_deadline_days'])->toBe(30);
});

it('grava a data de atualização no dia da clínica, não no de UTC', function () {
    // 23h30 em São Paulo já é dia 19 em UTC.
    Carbon::setTestNow(Carbon::parse('2026-09-18 23:30:00', 'America/Sao_Paulo'));

    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])
        ->assertOk()
        ->assertJsonPath('appeal_deadline_updated_at', '2026-09-18T23:30:00-03:00');
});

it('deixa editar convênio com prazo legado sem fonte se o prazo não mudar', function () {
    // Prazo cadastrado antes de a fonte existir.
    $this->link->update(['config' => ['appeal_deadline_days' => 30]]);

    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => null, 'ans_registry_code' => '123456'])
        ->assertOk()
        ->assertJsonPath('appeal_deadline_days', 30)
        ->assertJsonPath('appeal_deadline_source', null);

    expect(deadlineAudits())->toHaveCount(0);
});

it('exige fonte quando o prazo legado é alterado', function () {
    $this->link->update(['config' => ['appeal_deadline_days' => 30]]);

    putDeadline($this->link->id, ['appeal_deadline_days' => 45])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['appeal_deadline_source' => 'Informe a fonte do prazo (ex.: contrato e cláusula).']);
});

it('não deixa apagar a fonte de um prazo que já tem fonte', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();

    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => ''])
        ->assertStatus(422)->assertJsonValidationErrors('appeal_deadline_source');
});

it('não deixa outra clínica alterar nem excluir o vínculo', function () {
    putDeadline($this->link->id, ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato A'])->assertOk();
    $intrusa = makeUser(makeClinic('Clínica B'), 'owner');

    $this->actingAs($intrusa)->putJson("/api/payers/{$this->link->id}", [
        'name' => 'Convênio Teste', 'integration_type' => 'manual',
        'appeal_deadline_days' => 5, 'appeal_deadline_source' => 'Invasão',
    ])->assertNotFound();
    $this->actingAs($intrusa)->deleteJson("/api/payers/{$this->link->id}")->assertNotFound();

    $fresh = ClinicPayer::withoutGlobalScope('clinic')->find($this->link->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->appealDeadlineDays())->toBe(30)
        ->and($fresh->appealDeadlineSource())->toBe('Contrato A')
        ->and(deadlineAudits())->toHaveCount(1);
});
