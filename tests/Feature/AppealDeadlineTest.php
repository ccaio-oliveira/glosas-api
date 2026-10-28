<?php

use App\Models\ClinicPayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-18 10:00:00');

    $this->clinic = makeClinic();
    $this->actingAs(makeUser($this->clinic));
});

afterEach(fn () => Carbon::setTestNow());

/** Configura o prazo de recurso do convênio "Convênio Teste" nesta clínica. */
function setDeadline(App\Models\Clinic $clinic, ?int $days, string $payer = 'Convênio Teste'): void
{
    $link = ClinicPayer::withoutGlobalScope('clinic')
        ->where('clinic_id', $clinic->id)
        ->whereHas('payer', fn ($q) => $q->where('name', $payer))
        ->firstOrFail();

    $link->update(['config' => ['appeal_deadline_days' => $days]]);
}

function deadlineOf(int $denialId): array
{
    return test()->getJson("/api/denials/{$denialId}")->assertOk()->json('deadline');
}

it('calcula a data de vencimento a partir da identificação da glosa', function () {
    $d = makeDenial($this->clinic, ['identified_at' => '2026-09-01', 'service_date' => '2026-06-10']);
    setDeadline($this->clinic, 30);

    // 01/09 + 30 dias = 01/10 — conta da identificação, não do atendimento em junho
    expect(deadlineOf($d->id))
        ->toMatchArray(['status' => 'on_track', 'due_date' => '2026-10-01', 'days_left' => 13, 'deadline_days' => 30]);
});

it('marca como vencendo quando faltam 7 dias ou menos', function () {
    $d = makeDenial($this->clinic, ['identified_at' => '2026-08-25']);
    setDeadline($this->clinic, 30); // vence 24/09, faltam 6

    expect(deadlineOf($d->id))->toMatchArray(['status' => 'due_soon', 'days_left' => 6]);
});

it('marca como vencida com dias negativos', function () {
    $d = makeDenial($this->clinic, ['identified_at' => '2026-08-01']);
    setDeadline($this->clinic, 30); // venceu 31/08

    expect(deadlineOf($d->id))->toMatchArray(['status' => 'overdue', 'days_left' => -18]);
});

it('vence hoje conta como vencendo, não como vencida', function () {
    $d = makeDenial($this->clinic, ['identified_at' => '2026-08-19']);
    setDeadline($this->clinic, 30); // vence 18/09 = hoje

    expect(deadlineOf($d->id))->toMatchArray(['status' => 'due_soon', 'days_left' => 0]);
});

it('não presume prazo quando o convênio não tem um cadastrado', function () {
    $d = makeDenial($this->clinic, ['identified_at' => '2026-09-01']);

    expect(deadlineOf($d->id))
        ->toMatchArray(['status' => 'not_configured', 'due_date' => null, 'days_left' => null]);
});

it('não se aplica depois que a glosa foi contestada ou encerrada', function (string $status) {
    $d = makeDenial($this->clinic, ['identified_at' => '2026-08-01', 'status' => $status]);
    setDeadline($this->clinic, 30);

    expect(deadlineOf($d->id)['status'])->toBe('not_applicable');
})->with(['appealed', 'recovered', 'rejected']);

it('usa o prazo do convênio de cada glosa', function () {
    $a = makeDenial($this->clinic, ['identified_at' => '2026-09-01'], 'Convênio A');
    $b = makeDenial($this->clinic, ['identified_at' => '2026-09-01'], 'Convênio B');
    setDeadline($this->clinic, 10, 'Convênio A');
    setDeadline($this->clinic, 60, 'Convênio B');

    expect(deadlineOf($a->id)['status'])->toBe('overdue')
        ->and(deadlineOf($b->id)['status'])->toBe('on_track');
});

it('o prazo é do contrato de cada clínica, não do convênio global', function () {
    $outra = makeClinic('Outra');
    $minha = makeDenial($this->clinic, ['identified_at' => '2026-09-01']);
    makeDenial($outra, ['identified_at' => '2026-09-01']);

    setDeadline($outra, 5); // mesmo convênio, contrato de outra clínica

    expect(deadlineOf($minha->id)['status'])->toBe('not_configured');
});

it('filtra por prazo vencendo, incluindo as vencidas', function () {
    makeDenial($this->clinic, ['identified_at' => '2026-09-15', 'patient_name' => 'Folgada']);   // vence 15/10
    makeDenial($this->clinic, ['identified_at' => '2026-08-25', 'patient_name' => 'Apertada']);  // vence 24/09
    makeDenial($this->clinic, ['identified_at' => '2026-08-01', 'patient_name' => 'Vencida']);   // venceu 31/08
    setDeadline($this->clinic, 30);

    $vencendo = collect($this->getJson('/api/denials?deadline=due_soon')->json())->pluck('patient_name');
    $vencidas = collect($this->getJson('/api/denials?deadline=overdue')->json())->pluck('patient_name');

    expect($vencendo->sort()->values()->all())->toBe(['Apertada', 'Vencida'])
        ->and($vencidas->all())->toBe(['Vencida']);
});

it('conta vencendo, vencidas e sem prazo no resumo', function () {
    makeDenial($this->clinic, ['identified_at' => '2026-08-25'], 'Convênio A');   // vencendo
    makeDenial($this->clinic, ['identified_at' => '2026-08-01'], 'Convênio A');   // vencida
    makeDenial($this->clinic, ['identified_at' => '2026-08-01'], 'Convênio B');   // sem prazo
    makeDenial($this->clinic, ['identified_at' => '2026-08-01', 'status' => 'recovered'], 'Convênio A'); // fora
    setDeadline($this->clinic, 30, 'Convênio A');

    $this->getJson('/api/denials/summary')->assertOk()
        ->assertJsonPath('due_soon_count', 1)
        ->assertJsonPath('overdue_count', 1)
        ->assertJsonPath('deadline_not_configured_count', 1);
});

it('salva e devolve o prazo no cadastro do convênio, sem apagar o resto do config', function () {
    makeDenial($this->clinic);
    $link = ClinicPayer::withoutGlobalScope('clinic')->where('clinic_id', $this->clinic->id)->first();
    $link->update(['config' => ['outra_regra' => true]]);

    $this->putJson("/api/payers/{$link->id}", [
        'name' => 'Convênio Teste',
        'integration_type' => 'manual',
        'appeal_deadline_days' => 45,
        'appeal_deadline_source' => 'Contrato 2026, cláusula 7',
    ])->assertOk()->assertJsonPath('appeal_deadline_days', 45);

    expect($link->fresh()->config)->toMatchArray([
        'outra_regra' => true,
        'appeal_deadline_days' => 45,
        'appeal_deadline_source' => 'Contrato 2026, cláusula 7',
    ]);
});

it('limpa o prazo quando o campo vem vazio', function () {
    makeDenial($this->clinic);
    setDeadline($this->clinic, 30);
    $link = ClinicPayer::withoutGlobalScope('clinic')->where('clinic_id', $this->clinic->id)->first();

    $this->putJson("/api/payers/{$link->id}", [
        'name' => 'Convênio Teste', 'integration_type' => 'manual', 'appeal_deadline_days' => null,
    ])->assertOk()->assertJsonPath('appeal_deadline_days', null);
});

it('recusa prazo fora de 1 a 365 dias', function (int $dias) {
    makeDenial($this->clinic);
    $link = ClinicPayer::withoutGlobalScope('clinic')->where('clinic_id', $this->clinic->id)->first();

    $this->putJson("/api/payers/{$link->id}", [
        'name' => 'Convênio Teste', 'integration_type' => 'manual', 'appeal_deadline_days' => $dias,
    ])->assertStatus(422)->assertJsonValidationErrors('appeal_deadline_days');
})->with([0, -5, 400]);

it('expõe a fonte do prazo no detalhe da glosa, independente do status', function (string $status) {
    $d = makeDenial($this->clinic, ['identified_at' => '2026-09-01', 'status' => $status]);
    $link = ClinicPayer::withoutGlobalScope('clinic')->where('clinic_id', $this->clinic->id)->first();
    $link->update(['config' => ['appeal_deadline_days' => 30, 'appeal_deadline_source' => 'Contrato, cláusula 4']]);

    expect(deadlineOf($d->id))->toMatchArray(['deadline_days' => 30, 'deadline_source' => 'Contrato, cláusula 4']);
})->with(['new', 'recovered']);

it('devolve fonte nula quando o convênio não tem prazo', function () {
    $d = makeDenial($this->clinic, ['identified_at' => '2026-09-01']);

    expect(deadlineOf($d->id))->toMatchArray(['status' => 'not_configured', 'deadline_source' => null]);
});

it('recusa filtro de prazo desconhecido', function () {
    makeDenial($this->clinic);

    $this->getJson('/api/denials?deadline=foo')->assertStatus(422)->assertJsonValidationErrors('deadline');
    $this->getJson('/api/denials?deadline=')->assertOk();
    $this->getJson('/api/denials?deadline=not_configured')->assertOk()->assertJsonCount(1);
});

it('conta o dia pelo fuso de negócio, não por UTC', function () {
    // 23h30 em São Paulo de 18/09 já é 02h30 de 19/09 em UTC.
    Carbon::setTestNow(Carbon::parse('2026-09-18 23:30:00', 'America/Sao_Paulo'));

    $d = makeDenial($this->clinic, ['identified_at' => '2026-08-19']);
    setDeadline($this->clinic, 30); // vence 18/09 = ainda hoje em São Paulo

    expect(deadlineOf($d->id))->toMatchArray(['status' => 'due_soon', 'due_date' => '2026-09-18', 'days_left' => 0]);
});

it('usa o prazo da clínica de cada glosa mesmo sem clínica no contexto (super_admin)', function () {
    $outra = makeClinic('Outra');
    $minha = makeDenial($this->clinic, ['identified_at' => '2026-09-01']);
    $dela = makeDenial($outra, ['identified_at' => '2026-09-01']); // mesmo Payer
    setDeadline($this->clinic, 10);
    setDeadline($outra, 60);

    // O super_admin não alcança /denials (403, achado M6); o caso cross-tenant
    // real é serviço chamado fora do scope. Antes, o índice por payer_id puro
    // fazia a segunda clínica sobrescrever o prazo da primeira.
    $this->actingAs(App\Models\User::create([
        'clinic_id' => null, 'name' => 'Operador', 'email' => 'admin@glosasai.test',
        'password' => bcrypt('password'), 'role' => 'super_admin',
    ]));

    $denials = App\Models\Denial::withoutGlobalScope('clinic')
        ->with([
            'claimItem' => fn ($q) => $q->withoutGlobalScope('clinic'),
            'claimItem.claim' => fn ($q) => $q->withoutGlobalScope('clinic'),
        ])
        ->get()->keyBy('id');

    $service = new App\Services\Denials\AppealDeadline();

    expect($service->for($denials[$minha->id]))->toMatchArray(['deadline_days' => 10, 'status' => 'overdue'])
        ->and($service->for($denials[$dela->id]))->toMatchArray(['deadline_days' => 60, 'status' => 'on_track']);
});
