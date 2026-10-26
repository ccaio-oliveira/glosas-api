<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Não depender do .env local: o login só tem sessão se a origem for stateful.
    config(['sanctum.stateful' => ['localhost:5173']]);
    $this->user = makeUser(makeClinic(), 'owner', 'ana@clinica.test');
});

function attemptLogin(string $email, string $password = 'errada', string $ip = '10.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/login', ['email' => $email, 'password' => $password]);
}

it('continua autenticando com a senha certa', function () {
    attemptLogin('ana@clinica.test', 'password')->assertOk()->assertJsonPath('email', 'ana@clinica.test');
});

it('bloqueia a 6ª tentativa no mesmo e-mail com 429 e Retry-After', function () {
    foreach (range(1, 5) as $_) {
        attemptLogin('ana@clinica.test')->assertUnprocessable();
    }

    attemptLogin('ana@clinica.test')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Muitas tentativas de login.'));
});

it('mantém o bloqueio mesmo com a senha certa', function () {
    foreach (range(1, 5) as $_) {
        attemptLogin('ana@clinica.test');
    }

    attemptLogin('ana@clinica.test', 'password')->assertStatus(429);
    $this->assertGuest('web');
});

it('não deixa variar maiúsculas do e-mail para fugir do limite', function () {
    foreach (['ana@clinica.test', 'ANA@clinica.test', 'Ana@Clinica.test', 'aNa@clinica.TEST', 'ana@CLINICA.test'] as $email) {
        attemptLogin($email);
    }

    attemptLogin('ana@clinica.test')->assertStatus(429);
});

it('limita o IP que varre vários e-mails', function () {
    foreach (range(1, 20) as $i) {
        attemptLogin("tentativa{$i}@clinica.test")->assertUnprocessable();
    }

    attemptLogin('outro@clinica.test')->assertStatus(429);
});

it('responde 422, não 500, quando o e-mail chega como array ou ausente', function () {
    $this->postJson('/api/login', ['email' => ['ana@clinica.test'], 'password' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->postJson('/api/login', ['password' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('não bloqueia o mesmo e-mail vindo de outro IP', function () {
    foreach (range(1, 5) as $_) {
        attemptLogin('ana@clinica.test', ip: '10.0.0.1');
    }

    attemptLogin('ana@clinica.test', 'password', '10.0.0.2')->assertOk();
});
