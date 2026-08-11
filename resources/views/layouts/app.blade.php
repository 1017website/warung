<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ $activeStore?->brandName() ?? auth()->user()->tenant->name }}</title>
    @include('partials.static-assets')
    @stack('head')
</head>
<body>
@php
    $role = auth()->user()->role;
    $roleLabel = auth()->user()->roleLabel();
    $nav = auth()->user()->menu();
@endphp
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">
                @if($activeStore?->logo_path)
                    <img src="{{ asset('storage/'.$activeStore->logo_path) }}" alt="Logo">
                @else <i class="bi bi-shop"></i> @endif
            </span>
            <span class="brand-copy">
                <span class="brand-name">{{ $activeStore?->brandName() ?? auth()->user()->tenant->name }}</span>
                <span class="brand-sub">Warung OS</span>
            </span>
        </a>
        <div class="nav-label">Operasional</div>
        <nav class="nav">
            @foreach($nav as [$routeName, $icon, $label])
                <a href="{{ route($routeName) }}" class="{{ request()->routeIs($routeName.'*') ? 'active' : '' }}">
                    <span class="nav-icon"><i class="bi {{ $icon }}"></i></span><span>{{ $label }}</span>
                </a>
            @endforeach
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <span class="avatar">{{ collect(explode(' ', auth()->user()->name))->map(fn($n) => mb_substr($n,0,1))->take(2)->join('') }}</span>
                <span class="sidebar-user-copy">
                    <span class="sidebar-user-name">{{ auth()->user()->name }}</span>
                    <span class="sidebar-user-role">{{ $roleLabel }}</span>
                </span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" title="Keluar"><i class="bi bi-box-arrow-right"></i></button></form>
            </div>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <div><div class="top-title">@yield('title', 'Dashboard')</div><div class="top-sub">{{ now()->translatedFormat('l, d F Y') }}</div></div>
            @if(auth()->user()->canAccessAllStores())
                <form class="store-switch" action="{{ route('stores.switch') }}" method="POST">
                    @csrf
                    <label class="store-switch-label hide-mobile" for="active-store">
                        <span class="store-switch-icon"><i class="bi bi-geo-alt"></i></span>
                        <span>Cabang</span>
                    </label>
                    <select id="active-store" name="store_id" aria-label="Pilih cabang aktif" onchange="this.form.submit()">
                        <option value="consolidated" @selected($isConsolidated)>Consolidated · Semua warung</option>
                        @foreach($availableStores as $store)
                            <option value="{{ $store->id }}" @selected(!$isConsolidated && $activeStore?->id === $store->id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                </form>
            @else
                <div class="store-switch">
                    <span class="store-switch-label hide-mobile">
                        <span class="store-switch-icon"><i class="bi bi-geo-alt"></i></span>
                        <span>Cabang</span>
                    </span>
                    <span class="store-switch-static">{{ $activeStore?->name ?? '—' }}</span>
                </div>
            @endif
        </header>
        <div class="page">
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
            @yield('content')
        </div>
    </main>
</div>
<nav class="mobile-nav">
    @foreach($nav as [$routeName, $icon, $label])
        <a href="{{ route($routeName) }}" class="{{ request()->routeIs($routeName.'*') ? 'active' : '' }}"><i class="bi {{ $icon }}"></i><span>{{ $label }}</span></a>
    @endforeach
</nav>
@stack('modals')
@stack('scripts')
</body>
</html>
