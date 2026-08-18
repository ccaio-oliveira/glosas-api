<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $e) {
            if (app()->environment('testing')) {
                return;
            }

            if (
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
            ) {
                return;
            }

            try {
                \App\Models\ErrorLog::log(
                    category: 'general',
                    message: $e->getMessage() ?: get_class($e),
                    source: get_class($e),
                    context: [
                        'file' => $e->getFile().':'.$e->getLine(),
                        'trace' => collect(explode("\n", $e->getTraceAsString()))->take(20)->implode("\n"),
                        'url' => request()?->fullUrl(),
                    ],
                    severity: 'critical',
                    clinicId: auth()->check() ? auth()->user()->clinic_id : null
                );
            } catch (\Throwable) {
                // evita que uma falha ao logar o erro derrube a aplicação
            }
        });
    })->create();
