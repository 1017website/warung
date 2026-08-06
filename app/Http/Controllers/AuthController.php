<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function create()
    {
        if (! Auth::check()) {
            return view('auth.login');
        }

        return redirect()->route($this->landingRoute());
    }

    /** Ringkasan hanya untuk pemegang akses omset; sisanya langsung ke kasir. */
    private function landingRoute(): string
    {
        return Auth::user()->canAccess('dashboard') ? 'dashboard' : 'pos';
    }

    public function store(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);
        $credentials['is_active'] = true;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Email atau kata sandi tidak sesuai.'])->onlyInput('email');
        }

        // Role yang tidak ada di master (sisa role lama atau role terhapus) ditolak eksplisit,
        // bukan dibiarkan lolos lalu 403 di setiap halaman.
        if (! $request->user()->roleDefinition()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'Role akun ini sudah tidak berlaku. Hubungi Superadmin untuk pembaruan akses.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->put('store_id', $request->user()->store_id);

        return redirect()->intended(route($this->landingRoute()));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
