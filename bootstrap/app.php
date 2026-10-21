<?php

use App\Http\Middleware\EnsureModuleAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        // Aturan bisnis memakai abort(422, pesan). Laravel tidak punya halaman 422, sehingga di
        // produksi pengguna hanya melihat "Oops! An Error Occurred" tanpa pesan. Form biasa di
        // semua modul dikembalikan ke layar asal dengan pesan error dan isian tetap terisi;
        // pemanggil JSON (checkout POS, live edit stok) tetap menerima 422 apa adanya.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if ($exception->getStatusCode() !== 422 || $request->expectsJson()) {
                return null;
            }

            $message = $exception->getMessage() ?: 'Data belum dapat diproses. Periksa kembali isian Anda.';
            if (! $request->isMethod('GET')) {
                return back()->withErrors($message)->withInput(Arr::except($request->input(), [
                    'password', 'password_confirmation', 'current_password', 'pin', 'approval_pin', 'authorization_pin',
                ]));
            }

            // Halaman GET yang menolak dirinya sendiri tidak boleh diarahkan ke URL yang sama (loop).
            $previous = url()->previous();
            $target = $previous && $previous !== $request->fullUrl()
                ? $previous
                : ($request->user() ? route($request->user()->landingRoute()) : route('login'));

            return redirect()->to($target)->withErrors($message);
        });
    })->create();
