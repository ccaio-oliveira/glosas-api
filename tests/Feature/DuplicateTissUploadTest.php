<?php

use App\Jobs\ProcessTissUploadJob;
use App\Models\Clinic;
use App\Models\TissUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');

    $this->clinic = Clinic::create([
        'name' => 'Clínica de Teste',
        'cnpj' => '11222333000144',
    ]);

    $this->user = User::create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Dra. Teste',
        'email' => 'teste@clinica.test',
        'password' => bcrypt('password'),
        'role' => 'owner',
    ]);

    $this->actingAs($this->user);
});

function tissFile(string $name = 'demonstrativo-valido.xml'): UploadedFile
{
    return new UploadedFile(
        base_path("tests/fixtures/tiss/{$name}"),
        $name,
        'text/xml',
        null,
        true, // modo teste: não exige que seja upload HTTP real
    );
}

it('aceita o primeiro upload e grava o hash do conteúdo', function () {
    $response = $this->post('/api/tiss-uploads', ['file' => tissFile()]);

    $response->assertCreated();

    $upload = TissUpload::withoutGlobalScope('clinic')->first();
    expect($upload->content_hash)->toHaveLength(64);

    Queue::assertPushed(ProcessTissUploadJob::class);
});

it('recusa o mesmo conteúdo com 409 quando já existe upload processado', function () {
    $this->post('/api/tiss-uploads', ['file' => tissFile()])->assertCreated();

    TissUpload::withoutGlobalScope('clinic')->first()->update([
        'status' => 'processed',
        'claims_count' => 4,
        'denials_count' => 6,
    ]);

    $response = $this->post('/api/tiss-uploads', ['file' => tissFile()]);

    $response->assertStatus(409)
        ->assertJsonPath('duplicate.claims_count', 4)
        ->assertJsonPath('duplicate.denials_count', 6)
        ->assertJsonPath('duplicate.original_filename', 'demonstrativo-valido.xml');

    // nada foi gravado na tentativa recusada
    expect(TissUpload::withoutGlobalScope('clinic')->count())->toBe(1);
});

it('detecta duplicata mesmo com o arquivo renomeado', function () {
    $this->post('/api/tiss-uploads', ['file' => tissFile()])->assertCreated();
    TissUpload::withoutGlobalScope('clinic')->first()->update(['status' => 'processed']);

    $renamed = new UploadedFile(
        base_path('tests/fixtures/tiss/demonstrativo-valido.xml'),
        'demonstrativo (1).xml',
        'text/xml',
        null,
        true,
    );

    $this->post('/api/tiss-uploads', ['file' => $renamed])->assertStatus(409);
});

it('permite reenviar com force, criando um segundo upload', function () {
    $this->post('/api/tiss-uploads', ['file' => tissFile()])->assertCreated();
    TissUpload::withoutGlobalScope('clinic')->first()->update(['status' => 'processed']);

    $this->post('/api/tiss-uploads', ['file' => tissFile(), 'force' => '1'])->assertCreated();

    expect(TissUpload::withoutGlobalScope('clinic')->count())->toBe(2);
});

it('não bloqueia reenvio quando o upload anterior falhou', function () {
    $this->post('/api/tiss-uploads', ['file' => tissFile('arquivo-invalido.xml')])->assertCreated();

    TissUpload::withoutGlobalScope('clinic')->first()->update([
        'status' => 'failed',
        'error_message' => 'Arquivo XML inválido ou corrompido.',
    ]);

    // reenviar depois de falha é o comportamento esperado — não pode pedir force
    $this->post('/api/tiss-uploads', ['file' => tissFile('arquivo-invalido.xml')])->assertCreated();
});

it('não bloqueia por arquivo igual enviado em outra clínica', function () {
    $other = Clinic::create(['name' => 'Outra Clínica', 'cnpj' => '99888777000166']);

    TissUpload::withoutGlobalScope('clinic')->create([
        'clinic_id' => $other->id,
        'original_filename' => 'demonstrativo-valido.xml',
        'path' => 'tiss/outro.xml',
        'content_hash' => hash_file('sha256', base_path('tests/fixtures/tiss/demonstrativo-valido.xml')),
        'size_bytes' => 100,
        'status' => 'processed',
    ]);

    $this->post('/api/tiss-uploads', ['file' => tissFile()])->assertCreated();
});

it('bloqueia o upload para quem não tem permissão de operar', function () {
    $viewer = User::create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Visualizador',
        'email' => 'viewer@clinica.test',
        'password' => bcrypt('password'),
        'role' => 'viewer',
    ]);

    $this->actingAs($viewer)
        ->post('/api/tiss-uploads', ['file' => tissFile()])
        ->assertForbidden();
});
