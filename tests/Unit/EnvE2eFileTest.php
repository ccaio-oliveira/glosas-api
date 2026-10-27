<?php

/**
 * .env.e2e é versionado (ambiente E2E reprodutível). Este teste impede que ele vire
 * lugar de segredo ou aponte para um banco que não seja o sqlite descartável.
 */
function e2eEnv(): array
{
    $path = dirname(__DIR__, 2).'/.env.e2e';
    expect(file_exists($path))->toBeTrue();

    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
            $vars[$m[1]] = trim($m[2], " \"'");
        }
    }

    return $vars;
}

it('usa só sqlite, sem host nem credencial de banco', function () {
    $env = e2eEnv();

    expect($env['DB_CONNECTION'] ?? null)->toBe('sqlite');
    foreach (['DB_URL', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        expect($env)->not->toHaveKey($key);
    }
});

it('não guarda segredo além da APP_KEY de teste', function () {
    $suspicious = array_filter(
        array_keys(e2eEnv()),
        fn (string $key) => $key !== 'APP_KEY'
            && preg_match('/(PASSWORD|SECRET|_KEY|TOKEN|STRIPE|AWS_|ANTHROPIC|OPENAI|SENTRY)/', $key),
    );

    expect($suspicious)->toBe([]);
});

it('não reaproveita a APP_KEY do .env de dev', function () {
    $dev = dirname(__DIR__, 2).'/.env';
    if (! file_exists($dev)) {
        $this->markTestSkipped('sem .env local');
    }

    preg_match('/^APP_KEY=(.*)$/m', file_get_contents($dev), $m);
    expect(e2eEnv()['APP_KEY'])->not->toBe(trim($m[1] ?? '', " \"'"));
});

it('mantém o ambiente isolado: fila síncrona, e-mail em log, cookie próprio', function () {
    $env = e2eEnv();

    expect($env['APP_ENV'])->toBe('e2e')
        ->and($env['QUEUE_CONNECTION'])->toBe('sync')
        ->and($env['MAIL_MAILER'])->toBe('log')
        ->and($env['SESSION_COOKIE'])->toBe('glosas_e2e_session');
});
