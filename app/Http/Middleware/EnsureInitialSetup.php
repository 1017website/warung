<?php

namespace App\Http\Middleware;

use App\Services\InitialSetup;
use Closure;
use Illuminate\Http\Request;

class EnsureInitialSetup
{
    public function handle(Request $request, Closure $next)
    {
        if (app(InitialSetup::class)->required($request->user())) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Selesaikan setup awal terlebih dahulu.', 'redirect' => route('setup')], 409);
            }
            return redirect()->route('setup');
        }
        return $next($request);
    }
}
