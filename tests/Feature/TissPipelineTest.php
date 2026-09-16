<?php

use App\Jobs\ProcessTissUploadJob;
use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\ClinicPayer;
use App\Models\Denial;
use App\Models\ErrorLog;
use App\Models\Payer;
use App\Models\TissUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->clinic = makeClinic();
    $this->seed(Database\Seeders\DenialReasonCodeSeeder::class);
});

/**
 * Grava a fixture no storage falso e roda o job — o mesmo caminho do upload real,
 * sem passar pelo HTTP. O job roda fora de requisição, sem usuário autenticado:
 * é justamente isso que este teste precisa exercitar.
 */
function processFixture(App\Models\Clinic $clinic, string $fixture): TissUpload
{
    $contents = file_get_contents(base_path("tests/fixtures/tiss/{$fixture}"));
    $path = "tiss/{$fixture}";

    Storage::put($path, $contents);

    $upload = TissUpload::withoutGlobalScope('clinic')->create([
        'clinic_id' => $clinic->id,
        'original_filename' => $fixture,
        'path' => $path,
        'content_hash' => hash('sha256', $contents),
        'size_bytes' => strlen($contents),
        'status' => 'pending',
    ]);

    app(ProcessTissUploadJob::class, ['tissUploadId' => $upload->id])
        ->handle(app(App\Services\Tiss\TissXmlParser::class), app(App\Services\Tiss\DenialClassifier::class));

    return $upload->fresh();
}

it('transforma o demonstrativo nos números documentados na fixture', function () {
    $upload = processFixture($this->clinic, 'demonstrativo-valido.xml');

    // o cabeçalho da fixture promete: 4 guias · 9 itens · 6 glosas · R$ 1.492,00
    expect($upload->status)->toBe('processed')
        ->and($upload->claims_count)->toBe(4)
        ->and($upload->denials_count)->toBe(6);

    expect(Claim::withoutGlobalScope('clinic')->count())->toBe(4)
        ->and(ClaimItem::withoutGlobalScope('clinic')->count())->toBe(9)
        ->and(Denial::withoutGlobalScope('clinic')->count())->toBe(6)
        ->and((float) Denial::withoutGlobalScope('clinic')->sum('amount'))->toBe(1492.00);
});

it('não cria glosa para item pago integralmente', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');

    $pagos = ClaimItem::withoutGlobalScope('clinic')->where('denied_amount', 0)->count();

    // 9 itens, 6 glosados, 3 pagos — item pago não vira glosa
    expect($pagos)->toBe(3)
        ->and(Denial::withoutGlobalScope('clinic')->count())->toBe(9 - $pagos);
});

it('carimba clinic_id em tudo, mesmo rodando sem usuário autenticado', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');

    // o job roda em fila, fora de requisição: o hook de criação do BelongsToClinic
    // não tem de quem herdar, então o clinic_id precisa ir explícito
    expect(auth()->check())->toBeFalse()
        ->and(Claim::withoutGlobalScope('clinic')->whereNull('clinic_id')->count())->toBe(0)
        ->and(ClaimItem::withoutGlobalScope('clinic')->whereNull('clinic_id')->count())->toBe(0)
        ->and(Denial::withoutGlobalScope('clinic')->whereNull('clinic_id')->count())->toBe(0)
        ->and(Denial::withoutGlobalScope('clinic')->where('clinic_id', '!=', $this->clinic->id)->count())->toBe(0);
});

it('cria o convênio pelo registro ANS do arquivo e vincula à clínica', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');

    $payer = Payer::where('ans_registry_code', '999001')->first();

    expect($payer)->not->toBeNull()
        ->and(ClinicPayer::withoutGlobalScope('clinic')
            ->where('clinic_id', $this->clinic->id)->where('payer_id', $payer->id)->exists())->toBeTrue();
});

it('reaproveita o convênio entre clínicas em vez de duplicar', function () {
    $outra = makeClinic('Outra');

    processFixture($this->clinic, 'demonstrativo-valido.xml');
    processFixture($outra, 'demonstrativo-valido.xml');

    // catálogo de convênio é global; o vínculo é que é por clínica
    expect(Payer::where('ans_registry_code', '999001')->count())->toBe(1)
        ->and(ClinicPayer::withoutGlobalScope('clinic')->count())->toBe(2);
});

it('classifica pela Tabela 38 e marca para revisão o que não está catalogado', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');

    $conhecida = Denial::withoutGlobalScope('clinic')->where('reason_code', '1402')->first();
    $desconhecida = Denial::withoutGlobalScope('clinic')->where('reason_code', '9999')->first();

    expect($conhecida->needs_ai_review)->toBeFalse()
        ->and($conhecida->category)->not->toBe('unknown')
        ->and($conhecida->reason_description)->not->toBe('Código não catalogado');

    // 9999 não existe na Tabela 38 — é a rede de segurança do classificador
    expect($desconhecida->needs_ai_review)->toBeTrue()
        ->and($desconhecida->category)->toBe('unknown');
});

it('separa os convênios ao processar dois demonstrativos diferentes', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');
    processFixture($this->clinic, 'demonstrativo-convenio-b.xml');

    expect(Payer::count())->toBe(2)
        ->and(Claim::withoutGlobalScope('clinic')->count())->toBe(6)
        ->and(Denial::withoutGlobalScope('clinic')->count())->toBe(8)
        ->and((float) Denial::withoutGlobalScope('clinic')->sum('amount'))->toBe(1492.00 + 1130.00);
});

it('isola as clínicas: cada uma só enxerga as próprias glosas', function () {
    $outra = makeClinic('Outra');

    processFixture($this->clinic, 'demonstrativo-valido.xml');
    processFixture($outra, 'demonstrativo-convenio-b.xml');

    $this->actingAs(makeUser($this->clinic));
    expect(Denial::count())->toBe(6);

    $this->actingAs(makeUser($outra));
    expect(Denial::count())->toBe(2);
});

it('marca o upload como falho e registra no painel de erros quando o XML é inválido', function () {
    expect(fn () => processFixture($this->clinic, 'arquivo-invalido.xml'))
        ->toThrow(RuntimeException::class);

    $upload = TissUpload::withoutGlobalScope('clinic')->first();

    expect($upload->status)->toBe('failed')
        ->and($upload->error_message)->toContain('inválido');

    $log = ErrorLog::where('category', 'claim_processing')->first();

    expect($log)->not->toBeNull()
        ->and($log->clinic_id)->toBe($this->clinic->id)
        ->and($log->context['tiss_upload_id'])->toBe($upload->id);
});

it('não deixa nada gravado pela metade quando o arquivo quebra', function () {
    try {
        processFixture($this->clinic, 'arquivo-invalido.xml');
    } catch (Throwable) {
        // o relance é esperado — a fila precisa dele para marcar o job como falho
    }

    expect(Claim::withoutGlobalScope('clinic')->count())->toBe(0)
        ->and(Denial::withoutGlobalScope('clinic')->count())->toBe(0);
});

it('reprocessar o mesmo arquivo duplica — é o que o tiss:reprocess existe para evitar', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');
    processFixture($this->clinic, 'demonstrativo-valido.xml');

    // comportamento documentado, não desejado: o job não limpa nada antes de gravar
    expect(Denial::withoutGlobalScope('clinic')->count())->toBe(12);
});

it('usa a data do processamento como identified_at', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');

    $datas = Denial::withoutGlobalScope('clinic')->pluck('identified_at')->unique();

    // ATENÇÃO: é a data do upload, não a do atendimento. A fixture tem
    // atendimentos de julho, mas todas as glosas caem em hoje. Ver docs/estado-atual.md.
    expect($datas)->toHaveCount(1)
        ->and($datas->first()->toDateString())->toBe(now()->toDateString());
});

it('extrai a data de atendimento de cada guia do XML', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');

    $datas = Claim::withoutGlobalScope('clinic')
        ->orderBy('claim_number')->pluck('service_date')
        ->map(fn ($d) => $d?->toDateString())->all();

    // as quatro datas de dataAtendimento da fixture, não a data de hoje
    expect($datas)->toBe(['2026-07-14', '2026-07-16', '2026-07-21', '2026-07-28']);
});

it('o relatório passa a enxergar as glosas no mês do atendimento', function () {
    processFixture($this->clinic, 'demonstrativo-valido.xml');

    $this->actingAs(makeUser($this->clinic));
    $r = (new App\Services\Reports\RecoveryReport())->data();

    // todos os atendimentos da fixture são de julho, mesmo processados hoje
    expect($r['by_month'])->toHaveCount(1)
        ->and($r['by_month'][0]['label'])->toBe('jul/2026')
        ->and($r['by_month'][0]['denied_amount'])->toBe(1492.00);
});
