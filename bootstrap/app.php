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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $e) {
            if (app()->environment('testing')) {
                return;
            }

            try {
                \App\Models\ErrorLog::log(
                    category: 'general',
                    message: $e->getMessage(),
                    source: get_class($e),
                    context: ['trace' => $e->getTraceAsString()],
                    severity: 'critical',
                    clinicId: auth()->check() ? auth()->user()->clinic_id : null
                );
            } catch (\Throwable) {
                // evita que uma falha ao logar o erro derrube a aplicação
            }
        });
    })->create();
