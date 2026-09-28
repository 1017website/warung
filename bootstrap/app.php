<?php

use App\Http\Middleware\EnsureModuleAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            require __DIR__.'/../routes/maintenance.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'module' => EnsureModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Keep business-rule errors on the screen that submitted a regular form.
        // JSON callers such as POS checkout still receive the original 422.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $routeName = $request->route()?->getName();
            if ($exception->getStatusCode() !== 422
                || $request->expectsJson()
                || ! in_array($routeName, ['pos.checkout', 'pos.pending'], true)) {
                return null;
            }

            return back()->withErrors($exception->getMessage() ?: 'Data belum dapat diproses. Periksa kembali isian Anda.');
        });
    })->create();
