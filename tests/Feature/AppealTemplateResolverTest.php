<?php

use App\Models\AppealTemplate;
use App\Models\DenialReasonCode;
use App\Services\Appeals\AppealGenerator;
use App\Services\Appeals\AppealTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->clinic = makeClinic();
    $this->actingAs(makeUser($this->clinic));
    $this->resolver = app(AppealTemplateResolver::class);
});

function template(array $attributes = []): AppealTemplate
{
    return AppealTemplate::create(array_merge([
        'name' => 'Modelo de teste',
        'scope' => 'category',
        'category' => 'administrative',
        'body' => 'Corpo do modelo.',
        'is_active' => true,
    ], $attributes));
}

it('prefere o modelo de código exato sobre grupo e categoria', function () {
    DenialReasonCode::create(['code' => '1402', 'description' => 'Teste', 'category' => 'administrative', 'tiss_group' => 'guia']);

    template(['name' => 'Por categoria', 'scope' => 'category', 'category' => 'administrative']);
    template(['name' => 'Por grupo', 'scope' => 'group', 'tiss_group' => 'guia']);
    template(['name' => 'Por código', 'scope' => 'code', 'denial_reason_code' => '1402']);

    $denial = makeDenial($this->clinic, ['reason_code' => '1402', 'category' => 'administrative']);

    expect($this->resolver->resolve($denial)->name)->toBe('Por código');
});

it('cai para o modelo de grupo quando não há de código', function () {
    DenialReasonCode::create(['code' => '3021', 'description' => 'Teste', 'category' => 'technical', 'tiss_group' => 'odontologia']);

    template(['name' => 'Por categoria', 'scope' => 'category', 'category' => 'technical']);
    template(['name' => 'Por grupo', 'scope' => 'group', 'tiss_group' => 'odontologia']);

    $denial = makeDenial($this->clinic, ['reason_code' => '3021', 'category' => 'technical']);

    expect($this->resolver->resolve($denial)->name)->toBe('Por grupo');
});

it('cai para categoria quando o código não está catalogado', function () {
    template(['name' => 'Rede de segurança', 'scope' => 'category', 'category' => 'unknown']);

    // 9999 não existe na Tabela 38 — é o caso do arquivo de fixture
    $denial = makeDenial($this->clinic, ['reason_code' => '9999', 'category' => 'unknown']);

    expect($this->resolver->resolve($denial)->name)->toBe('Rede de segurança');
});

it('prefere o modelo da própria clínica sobre o global de mesma especificidade', function () {
    template(['name' => 'Global', 'scope' => 'code', 'denial_reason_code' => '1402', 'clinic_id' => null]);
    template(['name' => 'Da clínica', 'scope' => 'code', 'denial_reason_code' => '1402', 'clinic_id' => $this->clinic->id]);

    $denial = makeDenial($this->clinic, ['reason_code' => '1402']);

    expect($this->resolver->resolve($denial)->name)->toBe('Da clínica');
});

it('nunca usa modelo privado de outra clínica', function () {
    $other = makeClinic('Outra');

    template(['name' => 'Da outra clínica', 'scope' => 'code', 'denial_reason_code' => '1402', 'clinic_id' => $other->id]);
    template(['name' => 'Global', 'scope' => 'category', 'category' => 'administrative', 'clinic_id' => null]);

    $denial = makeDenial($this->clinic, ['reason_code' => '1402', 'category' => 'administrative']);

    // mesmo sendo menos específico, o global vence: o privado alheio não existe para esta clínica
    expect($this->resolver->resolve($denial)->name)->toBe('Global');
});

it('ignora modelo inativo', function () {
    template(['name' => 'Desativado', 'scope' => 'code', 'denial_reason_code' => '1402', 'is_active' => false]);
    template(['name' => 'Ativo', 'scope' => 'category', 'category' => 'administrative']);

    $denial = makeDenial($this->clinic, ['reason_code' => '1402', 'category' => 'administrative']);

    expect($this->resolver->resolve($denial)->name)->toBe('Ativo');
});

it('devolve null quando nenhum modelo cobre a glosa', function () {
    $denial = makeDenial($this->clinic, ['reason_code' => '1402', 'category' => 'administrative']);

    expect($this->resolver->resolve($denial))->toBeNull();
});

it('os modelos semeados cobrem todas as categorias — a cobertura de 100% não é promessa vazia', function () {
    $this->seed(Database\Seeders\AppealTemplateSeeder::class);

    foreach (['administrative', 'technical', 'linear', 'unknown'] as $category) {
        $denial = makeDenial($this->clinic, [
            'reason_code' => '0000',   // código inexistente: força a queda até a categoria
            'category' => $category,
        ]);

        expect($this->resolver->resolve($denial))
            ->not->toBeNull("categoria {$category} ficou sem modelo");
    }
});

it('marca a origem conforme o degrau que resolveu', function () {
    DenialReasonCode::create(['code' => '1402', 'description' => 'T', 'category' => 'administrative', 'tiss_group' => 'guia']);
    template(['scope' => 'group', 'tiss_group' => 'guia']);

    $generated = app(AppealGenerator::class)->generate(
        makeDenial($this->clinic, ['reason_code' => '1402', 'category' => 'administrative'])
    );

    // é essa string que a coluna "Origem" da tela de Recursos exibe
    expect($generated->source)->toBe('template_group');
});

it('sinaliza fundamentação legal ausente em vez de inventar uma', function () {
    template(['scope' => 'category', 'category' => 'administrative', 'legal_basis' => null, 'body' => 'Base: {{legal_basis}}']);

    $generated = app(AppealGenerator::class)->generate(
        makeDenial($this->clinic, ['category' => 'administrative', 'reason_code' => null])
    );

    expect($generated->missingLegalBasis)->toBeTrue()
        ->and($generated->text)->toContain('NÃO CADASTRADA');
});

it('deixa marcador visível quando falta a justificativa clínica', function () {
    template([
        'scope' => 'category',
        'category' => 'technical',
        'requires_clinical_input' => true,
        'body' => "Justificativa:\n{{clinical_section}}",
    ]);

    $denial = makeDenial($this->clinic, ['category' => 'technical', 'reason_code' => null]);
    $generator = app(AppealGenerator::class);

    $semTexto = $generator->generate($denial);
    expect($semTexto->requiresClinicalInput)->toBeTrue()
        ->and($semTexto->text)->toContain(AppealGenerator::CLINICAL_PLACEHOLDER);

    $comTexto = $generator->generate($denial, 'Paciente apresentava lesão cariosa extensa.');
    expect($comTexto->requiresClinicalInput)->toBeFalse()
        ->and($comTexto->text)->toContain('lesão cariosa extensa')
        ->and($comTexto->text)->not->toContain(AppealGenerator::CLINICAL_PLACEHOLDER);
});

it('preenche os dados da guia no texto gerado', function () {
    template(['scope' => 'category', 'category' => 'administrative',
        'body' => 'Guia {{claim_number}}, paciente {{patient_name}}, glosado {{denied_amount}}.']);

    $denial = makeDenial($this->clinic, [
        'category' => 'administrative', 'reason_code' => null,
        'amount' => 1234.5, 'patient_name' => 'Maria Oliveira',
    ]);

    $text = app(AppealGenerator::class)->generate($denial)->text;

    expect($text)->toContain('Maria Oliveira')
        ->and($text)->toContain('R$ 1.234,50')
        ->and($text)->not->toContain('{{');
});
