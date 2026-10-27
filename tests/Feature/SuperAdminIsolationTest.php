<?php

use App\Models\Appeal;
use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Denial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Achado M6: o super_admin (operador da SaaS, clinic_id nulo) não acessa as rotas
 * operacionais das clínicas. Mantém só /user, /logout e o painel /admin.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->clinic = makeClinic();
    $this->denial = makeDenial($this->clinic);
    // Lido antes do actingAs: autenticado como super_admin, o scope esconde o item.
    $this->claimId = $this->denial->claimItem->claim_id;
    $this->admin = User::create([
        'clinic_id' => null,
        'name' => 'Operador',
        'email' => 'admin@glosasai.test',
        'password' => bcrypt('password'),
        'role' => 'super_admin',
    ]);
});

// Rotas que o super_admin pode usar. Qualquer outra rota da API deve dar 403 a ele.
const SUPER_ADMIN_ALLOWED = ['api/login', 'api/logout', 'api/user'];

function clinicApiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
        ->reject(fn ($r) => in_array($r->uri(), SUPER_ADMIN_ALLOWED, true))
        ->reject(fn ($r) => str_starts_with($r->uri(), 'api/admin/'))
        ->flatMap(fn ($r) => collect($r->methods())
            ->reject(fn ($m) => $m === 'HEAD')
            ->map(fn ($m) => [$m, $r->uri()]))
        ->values()
        ->all();
}

it('cobre todas as rotas da clínica com o middleware clinic', function () {
    $routes = clinicApiRoutes();

    // Rede contra a lista vazia: se o filtro quebrar, o teste abaixo passaria sem testar nada.
    expect(count($routes))->toBeGreaterThan(30);

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array([$route->methods()[0], $route->uri()], $routes, true)) {
            continue;
        }

        expect(in_array('clinic', $route->gatherMiddleware(), true))
            ->toBeTrue("sem middleware clinic: {$route->uri()}");
    }
});

it('nega ao super_admin toda rota da clínica, leitura e escrita', function () {
    $ids = ['denial' => $this->denial->id, 'claim' => $this->claimId];

    foreach (clinicApiRoutes() as [$method, $uri]) {
        $path = '/'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($ids[$m[1]] ?? 1), $uri);

        $status = $this->actingAs($this->admin)->json($method, $path, [])->status();

        expect($status)->toBe(403, "{$method} {$path} devolveu {$status} ao super_admin");
    }
});

it('não altera nada quando o super_admin tenta escrever', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/denials/{$this->denial->id}", ['status' => 'appealed'])
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->deleteJson("/api/claims/{$this->claimId}")
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->postJson("/api/denials/{$this->denial->id}/appeal", ['text' => 'x'])
        ->assertForbidden();

    expect($this->denial->fresh()->status)->toBe('new')
        ->and(Claim::withoutGlobalScope('clinic')->count())->toBe(1)
        ->and(Appeal::withoutGlobalScope('clinic')->count())->toBe(0);
});

it('mantém o painel de erros e o /api/user para o super_admin', function () {
    $this->actingAs($this->admin)->getJson('/api/admin/error-logs')->assertOk();
    $this->actingAs($this->admin)->getJson('/api/admin/error-logs/summary')->assertOk();

    $this->actingAs($this->admin)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('role', 'super_admin')
        ->assertJsonPath('permissions', [
            'operate' => false, 'manage_clinic' => false, 'manage_users' => false, 'manage_billing' => false,
        ]);
});

it('não devolve linha de clínica nenhuma a usuário sem clínica', function () {
    makeDenial(makeClinic('Outra'));

    $this->actingAs($this->admin);

    expect(Denial::count())->toBe(0)
        ->and(Claim::count())->toBe(0)
        ->and(ClaimItem::count())->toBe(0);
});

it('isola uma clínica da outra no scope', function () {
    $outra = makeClinic('Outra');
    makeDenial($outra);

    $this->actingAs(makeUser($this->clinic, 'owner'));

    expect(Denial::count())->toBe(1)
        ->and(Denial::first()->clinic_id)->toBe($this->clinic->id);
});

it('recusa criar registro de clínica autenticado como usuário sem clínica', function () {
    $this->actingAs($this->admin);

    Claim::create(['claim_number' => 'X', 'patient_name' => 'Teste', 'total_amount' => 0, 'status' => 'pending']);
})->throws(LogicException::class);
