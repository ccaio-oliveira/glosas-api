<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
|--------------------------------------------------------------------------
| Helpers de domínio
|--------------------------------------------------------------------------
|
| Montar uma glosa exige a cadeia convênio → guia → item → glosa. Sem estes
| helpers, cada teste repetiria ~30 linhas de setup.
|
*/

function makeClinic(string $name = 'Clínica de Teste', ?string $cnpj = null): App\Models\Clinic
{
    return App\Models\Clinic::create([
        'name' => $name,
        'cnpj' => $cnpj ?? (string) random_int(10000000000000, 99999999999999),
    ]);
}

function makeUser(App\Models\Clinic $clinic, string $role = 'owner', ?string $email = null): App\Models\User
{
    return App\Models\User::create([
        'clinic_id' => $clinic->id,
        'name' => ucfirst($role).' de Teste',
        'email' => $email ?? $role.'-'.random_int(1000, 9999).'@clinica.test',
        'password' => bcrypt('password'),
        'role' => $role,
    ]);
}

/** Cria a cadeia completa e devolve a glosa. */
function makeDenial(
    App\Models\Clinic $clinic,
    array $attributes = [],
    string $payerName = 'Convênio Teste',
): App\Models\Denial {
    $payer = App\Models\Payer::firstOrCreate(
        ['name' => $payerName],
        ['ans_registry_code' => substr(md5($payerName), 0, 6)],
    );

    $amount = $attributes['amount'] ?? 500.00;

    $claim = App\Models\Claim::withoutGlobalScope('clinic')->create([
        'clinic_id' => $clinic->id,
        'payer_id' => $payer->id,
        'claim_number' => (string) random_int(100000, 999999),
        'service_date' => $attributes['service_date'] ?? null,
        'patient_name' => $attributes['patient_name'] ?? 'Paciente Teste',
        'total_amount' => $amount,
        'status' => 'processed',
    ]);

    $item = App\Models\ClaimItem::withoutGlobalScope('clinic')->create([
        'clinic_id' => $clinic->id,
        'claim_id' => $claim->id,
        'procedure_code' => '81000234',
        'description' => 'Restauração em resina composta',
        'billed_amount' => $amount,
        'denied_amount' => $amount,
    ]);

    return App\Models\Denial::withoutGlobalScope('clinic')->create([
        'clinic_id' => $clinic->id,
        'claim_item_id' => $item->id,
        'category' => $attributes['category'] ?? 'administrative',
        'reason_code' => $attributes['reason_code'] ?? '1402',
        'reason_description' => $attributes['reason_description'] ?? 'Motivo de teste',
        'amount' => $amount,
        'status' => $attributes['status'] ?? 'new',
        'identified_at' => $attributes['identified_at'] ?? '2026-08-01',
    ]);
}
