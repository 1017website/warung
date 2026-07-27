<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk · WarungKita</title>
    @include('partials.static-assets')
</head>
<body>
<div class="login-page">
    <section class="login-art">
        <div class="login-logo"><span class="brand-mark"><i class="bi bi-shop"></i></span> WarungKita</div>
        <div class="login-copy">
            <h1>Warung rapi,<br>jualan makin pasti.</h1>
            <p>Satu ruang kerja yang tenang untuk kasir, stok, member, pembelian, dan laporan semua cabang warung Anda.</p>
        </div>
        <div class="login-proof">
            <div><b>Multi-cabang</b><span>Kelola dalam satu akun</span></div>
            <div><b>Real-time</b><span>Stok & laporan selalu sinkron</span></div>
            <div><b>Mobile</b><span>Nyaman di perangkat apa pun</span></div>
        </div>
    </section>
    <section class="login-panel">
        <form class="login-card" method="POST" action="{{ route('login.store') }}">
            @csrf
            <h2>Selamat datang</h2>
            <p>Masuk untuk mulai mengelola warung hari ini.</p>
            @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
            <div class="field"><label>Email</label><input type="email" name="email" value="{{ old('email', 'admin@warungkita.id') }}" autocomplete="email" required autofocus></div>
            <div class="field"><label>Kata sandi</label><input type="password" name="password" value="password" autocomplete="current-password" required></div>
            <label style="display:flex;align-items:center;gap:8px;font-weight:600"><input style="width:16px;min-height:16px" type="checkbox" name="remember"> Ingat saya</label>
            <button class="btn btn-primary"><i class="bi bi-arrow-right-circle"></i> Masuk ke WarungKita</button>
            <div class="demo-box"><b><i class="bi bi-info-circle"></i> Akun demo</b><br>admin@warungkita.id · password</div>
        </form>
    </section>
</div>
</body>
</html>
