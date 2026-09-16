<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->clinic = makeClinic();
    $this->denial = makeDenial($this->clinic);
});

/**
 * Matriz de escrita: cada rota com o papel mínimo que pode executá-la.
 * owner faz tudo; biller opera; viewer não escreve nada.
 */
dataset('rotas de escrita', [
    'criar convênio'      => ['post',   '/api/payers', ['name' => 'Novo'], 'operate'],
    'enviar XML'          => ['post',   '/api/tiss-uploads', [], 'operate'],
    'mudar status glosa'  => ['put',    '/api/denials/{denial}', ['status' => 'pending'], 'operate'],
    'gerar recurso'       => ['post',   '/api/denials/{denial}/appeal/generate', [], 'operate'],
    'salvar recurso'      => ['post',   '/api/denials/{denial}/appeal', ['text' => 'x'], 'operate'],
    'dados da clínica'    => ['put',    '/api/clinic', ['name' => 'X'], 'manage-clinic'],
    'trocar de plano'     => ['put',    '/api/clinic/plan', ['plan' => 'professional'], 'manage-billing'],
    'convidar usuário'    => ['post',   '/api/users', ['name' => 'N', 'email' => 'n@t.test', 'role' => 'biller'], 'manage-users'],
]);

it('nega toda escrita ao viewer', function (string $method, string $path, array $payload, string $gate) {
    $path = str_replace('{denial}', (string) $this->denial->id, $path);

    $this->actingAs(makeUser($this->clinic, 'viewer'))
        ->{$method.'Json'}($path, $payload)
        ->assertForbidden();
})->with('rotas de escrita');

it('permite ao biller só o que é operação', function (string $method, string $path, array $payload, string $gate) {
    $path = str_replace('{denial}', (string) $this->denial->id, $path);

    $response = $this->actingAs(makeUser($this->clinic, 'biller'))->{$method.'Json'}($path, $payload);

    $gate === 'operate'
        ? expect($response->status())->not->toBe(403, "biller deveria poder: {$path}")
        : $response->assertForbidden();
})->with('rotas de escrita');

it('não barra o owner em nenhuma escrita', function (string $method, string $path, array $payload, string $gate) {
    $path = str_replace('{denial}', (string) $this->denial->id, $path);

    $response = $this->actingAs(makeUser($this->clinic, 'owner'))->{$method.'Json'}($path, $payload);

    expect($response->status())->not->toBe(403, "owner foi barrado em: {$path}");
})->with('rotas de escrita');

dataset('rotas de leitura', [
    '/api/clinic',
    '/api/payers',
    '/api/claims',
    '/api/denials',
    '/api/denials/summary',
    '/api/appeals',
    '/api/appeals/summary',
    '/api/tiss-uploads',
    '/api/reports/recovery',
    '/api/users',
]);

it('deixa o viewer ler tudo', function (string $path) {
    $this->actingAs(makeUser($this->clinic, 'viewer'))
        ->getJson($path)
        ->assertOk();
})->with('rotas de leitura');

it('exige autenticação em qualquer rota', function (string $path) {
    $this->getJson($path)->assertUnauthorized();
})->with('rotas de leitura');

it('devolve no /api/user as permissões que de fato valem nas rotas', function (string $role, array $expected) {
    $permissions = $this->actingAs(makeUser($this->clinic, $role))
        ->getJson('/api/user')->assertOk()->json('permissions');

    expect($permissions)->toBe($expected);
})->with([
    ['owner',  ['operate' => true,  'manage_clinic' => true,  'manage_users' => true,  'manage_billing' => true]],
    ['biller', ['operate' => true,  'manage_clinic' => false, 'manage_users' => false, 'manage_billing' => false]],
    ['viewer', ['operate' => false, 'manage_clinic' => false, 'manage_users' => false, 'manage_billing' => false]],
]);

it('barra usuário comum no painel de super_admin', function (string $role) {
    $this->actingAs(makeUser($this->clinic, $role))
        ->getJson('/api/admin/error-logs')
        ->assertForbidden();
})->with(['owner', 'biller', 'viewer']);

it('libera o super_admin, que tem clinic_id nulo', function () {
    $admin = User::create([
        'clinic_id' => null,
        'name' => 'Operador',
        'email' => 'admin@glosasai.test',
        'password' => bcrypt('password'),
        'role' => 'super_admin',
    ]);

    $this->actingAs($admin)->getJson('/api/admin/error-logs')->assertOk();
});

it('não deixa a clínica ficar sem nenhum owner', function () {
    $owner = makeUser($this->clinic, 'owner');
    $biller = makeUser($this->clinic, 'biller');

    // rebaixar o único owner
    $this->actingAs($owner)
        ->putJson("/api/users/{$owner->id}", ['role' => 'biller'])
        ->assertStatus(422);

    // com um segundo owner, o rebaixamento passa
    $this->actingAs($owner)->putJson("/api/users/{$biller->id}", ['role' => 'owner'])->assertOk();
    $this->actingAs($owner)->putJson("/api/users/{$owner->id}", ['role' => 'biller'])->assertOk();
});

it('não deixa remover o último owner', function () {
    $owner = makeUser($this->clinic, 'owner');
    $other = makeUser($this->clinic, 'biller');

    $this->actingAs($other)->assertAuthenticated();

    $this->actingAs($owner)
        ->deleteJson("/api/users/{$owner->id}")
        ->assertStatus(422);
});

it('não enxerga usuário de outra clínica', function () {
    $outra = makeClinic('Outra');
    $alvo = makeUser($outra, 'biller');

    // 404 e não 403 de propósito: 403 confirmaria que o usuário existe
    $this->actingAs(makeUser($this->clinic, 'owner'))
        ->putJson("/api/users/{$alvo->id}", ['role' => 'viewer'])
        ->assertNotFound();

    expect($alvo->fresh()->role)->toBe('biller');
});
