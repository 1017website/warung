<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hak akses dibaca dari master role saat request berjalan, bukan saat route didaftarkan,
 * supaya perubahan hak akses oleh Superadmin langsung berlaku dan tetap benar
 * walaupun route sedang di-cache.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($request->user() && $request->user()->canAccess($module), 403);

        return $next($request);
    }
}
