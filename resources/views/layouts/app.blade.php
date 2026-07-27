<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ auth()->user()->tenant->name }}</title>
    @include('partials.static-assets')
    @stack('head')
</head>
<body>
@php
    $role = auth()->user()->role;
    $nav = [
        ['dashboard', 'bi-grid-1x2', 'Ringkasan', ['superadmin','owner','admin','cashier','warehouse']],
        ['pos', 'bi-calculator', 'Kasir', ['superadmin','owner','admin','cashier']],
        ['transactions', 'bi-receipt', 'Transaksi', ['superadmin','owner','admin','cashier']],
        ['products', 'bi-box-seam', 'Produk', ['superadmin','owner','admin','warehouse']],
        ['inventory', 'bi-boxes', 'Stok / Gudang', ['superadmin','owner','admin','warehouse']],
        ['purchases', 'bi-bag-check', 'Pembelian', ['superadmin','owner','admin','warehouse']],
        ['expenses', 'bi-wallet2', 'Pengeluaran', ['superadmin','owner','admin']],
        ['members', 'bi-people', 'Membership', ['superadmin','owner','admin']],
        ['reports', 'bi-bar-chart', 'Laporan', ['superadmin','owner','admin']],
        ['settings', 'bi-sliders', 'Pengaturan', ['superadmin','owner','admin']],
    ];
@endphp
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">
                @if(auth()->user()->tenant->logo_path)
                    <img src="{{ asset('storage/'.auth()->user()->tenant->logo_path) }}" alt="Logo">
                @else <i class="bi bi-shop"></i> @endif
            </span>
            <span><span class="brand-name">{{ auth()->user()->tenant->name }}</span><span class="brand-sub">Warung OS</span></span>
        </a>
        <div class="nav-label">Operasional</div>
        <nav class="nav">
            @foreach($nav as [$routeName, $icon, $label, $roles])
                @if(in_array($role, $roles))
                    <a href="{{ route($routeName) }}" class="{{ request()->routeIs($routeName.'*') ? 'active' : '' }}">
                        <span class="nav-icon"><i class="bi {{ $icon }}"></i></span><span>{{ $label }}</span>
                    </a>
                @endif
            @endforeach
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <span class="avatar">{{ collect(explode(' ', auth()->user()->name))->map(fn($n) => mb_substr($n,0,1))->take(2)->join('') }}</span>
                <span><span class="sidebar-user-name">{{ auth()->user()->name }}</span><span class="sidebar-user-role">{{ $role }}</span></span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" title="Keluar"><i class="bi bi-box-arrow-right"></i></button></form>
            </div>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <div><div class="top-title">@yield('title', 'Dashboard')</div><div class="top-sub">{{ now()->translatedFormat('l, d F Y') }}</div></div>
            <form class="store-switch" action="{{ route('stores.switch') }}" method="POST">
                @csrf
                <label class="store-switch-label hide-mobile" for="active-store">
                    <span class="store-switch-icon"><i class="bi bi-geo-alt"></i></span>
                    <span>Cabang</span>
                </label>
                <select id="active-store" name="store_id" aria-label="Pilih cabang aktif" onchange="this.form.submit()">
                    @foreach($availableStores as $store)
                        <option value="{{ $store->id }}" @selected($activeStore?->id === $store->id)>{{ $store->name }}</option>
                    @endforeach
                </select>
            </form>
        </header>
        <div class="page">
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
            @yield('content')
        </div>
    </main>
</div>
<nav class="mobile-nav">
    @foreach(collect($nav)->filter(fn($n) => in_array($role, $n[3])) as [$routeName, $icon, $label])
        <a href="{{ route($routeName) }}" class="{{ request()->routeIs($routeName.'*') ? 'active' : '' }}"><i class="bi {{ $icon }}"></i><span>{{ $label }}</span></a>
    @endforeach
</nav>
@stack('modals')
@stack('scripts')
</body>
</html>
