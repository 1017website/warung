<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup Awal · POS Warung</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6fa;color:#19273a;font:16px/1.5 system-ui,sans-serif}header{background:#fff;border-bottom:1px solid #dde3ec;padding:18px max(20px,calc((100% - 960px)/2));display:flex;align-items:center;justify-content:space-between;gap:16px}main{max-width:820px;margin:36px auto;padding:0 20px 48px}h1{font-size:30px;margin:0 0 8px}h2{font-size:22px;margin-top:0}p{color:#576579}.steps{display:flex;list-style:none;padding:0;gap:12px;margin:26px 0}.steps li{flex:1;border-top:4px solid #d6deeb;padding:12px 0;font-weight:600}.steps .active{border-color:#2563eb;color:#1d4ed8}.card{padding:28px;background:white;border:1px solid #dde3ec;border-radius:16px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.field{display:flex;flex-direction:column;gap:7px;margin-bottom:18px}.grid .field{margin-bottom:0}label{font-weight:600}input,textarea{width:100%;padding:12px;border:1px solid #aab7ca;border-radius:8px;font:inherit;min-width:0}button{border:0;border-radius:8px;padding:12px 18px;min-height:44px;font:inherit;cursor:pointer;background:#2563eb;color:#fff;font-weight:600}.logout{background:#edf1f7;color:#263b58}.actions{margin-top:26px;display:flex;justify-content:flex-end}.error{padding:14px;background:#fff0f0;color:#991b1b;border-radius:8px;margin-bottom:20px}.hint{font-size:14px;margin:7px 0 20px}.review{padding:0;list-style:none}.review li{padding:12px 0;border-bottom:1px solid #e4e9f0;overflow-wrap:anywhere}.check{display:flex;gap:10px;align-items:flex-start}.check input{width:20px;flex:none;margin-top:4px}.badge{color:#167348;font-weight:700}.product{font-weight:600}button:focus-visible,input:focus-visible,textarea:focus-visible{outline:3px solid #93b4fc;outline-offset:3px}@media(max-width:600px){main{margin-top:24px}.card{padding:20px}.grid{grid-template-columns:1fr}.steps{gap:8px;font-size:13px}h1{font-size:26px}.actions button{width:100%}header{padding:14px 20px}}
</style>
</head>
<body>
<header><strong>POS Warung</strong><form method="post" action="{{ route('logout') }}">@csrf<button class="logout">Keluar</button></form></header>
<main>
@if(! $user->canManageSystem())
<section class="card"><h1>Warung belum siap digunakan</h1><p>Superadmin perlu melengkapi setup awal usaha, cabang, dan produk. Silakan hubungi Superadmin, lalu masuk kembali setelah setup selesai.</p></section>
@else
<h1>Siapkan warung Anda</h1><p>Lengkapi data awal agar kasir bisa mulai berjualan. Progres tersimpan setiap kali Anda melanjutkan langkah.</p>
<ol class="steps" aria-label="Progres setup"><li class="{{ $step >= 1 ? 'active' : '' }}" @if($step === 1) aria-current="step" @endif>1. Usaha & cabang</li><li class="{{ $step >= 2 ? 'active' : '' }}" @if($step === 2) aria-current="step" @endif>2. Produk awal</li><li class="{{ $step >= 3 ? 'active' : '' }}" @if($step === 3) aria-current="step" @endif>3. Siap digunakan</li></ol>
<section class="card">
@if($errors->any())<div class="error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="post" action="{{ route('setup.save') }}">
@csrf<input type="hidden" name="step" value="{{ $step }}">
@if($step === 1)
<h2>Identitas usaha dan cabang pertama</h2><p class="hint">Usaha menaungi semua cabang. Cabang adalah lokasi berjualan dengan stok dan transaksi masing-masing.</p>
<div class="field"><label for="business_name">Nama usaha</label><input id="business_name" name="business_name" value="{{ old('business_name', $tenant?->name) }}" maxlength="255" required autocomplete="organization"></div>
<div class="field"><label for="store_name">Nama cabang</label><input id="store_name" name="store_name" value="{{ old('store_name', $store?->name) }}" placeholder="Contoh: Cabang Pusat" maxlength="255" required></div>
<div class="field"><label for="address">Alamat cabang</label><textarea id="address" name="address" maxlength="255" required autocomplete="street-address">{{ old('address', $store?->address) }}</textarea></div>
<div class="grid"><div class="field"><label for="phone">Nomor telepon (opsional)</label><input id="phone" name="phone" type="tel" value="{{ old('phone', $store?->phone) }}" maxlength="30" autocomplete="tel"></div><div class="field"><label for="member_discount_percent">Diskon member (%)</label><input id="member_discount_percent" name="member_discount_percent" type="number" min="0" max="100" step="0.01" value="{{ old('member_discount_percent', $tenant?->member_discount_percent ?? 0) }}" required></div></div>
<p class="hint">Isi 0 jika belum menggunakan diskon member. Dapat diubah di Pengaturan.</p>
<div class="field"><label for="receipt_footer">Pesan penutup struk (opsional)</label><input id="receipt_footer" name="receipt_footer" value="{{ old('receipt_footer', $store?->receipt_footer ?? 'Terima kasih atas kunjungan Anda.') }}" maxlength="255"></div>
<div class="actions"><button>Simpan & lanjutkan</button></div>
@elseif($step === 2)
<h2>Tambahkan menu pertama</h2><p class="hint">Buat satu menu beserta stok siap jual. Menu lainnya dapat ditambahkan melalui Produk, sedangkan bahan baku dan stok produksi melalui Gudang.</p>
<div class="field"><label for="category">Kategori</label><input id="category" name="category" value="{{ old('category') }}" maxlength="255" placeholder="Contoh: Makanan" required></div>
<div class="field"><label for="product_name">Nama menu</label><input id="product_name" name="product_name" value="{{ old('product_name') }}" maxlength="255" placeholder="Contoh: Nasi Goreng" required></div>
<div class="grid"><div class="field"><label for="selling_price">Harga jual (Rp)</label><input id="selling_price" name="selling_price" type="number" min="1" max="999999999999" step="0.01" value="{{ old('selling_price') }}" required></div><div class="field"><label for="unit">Satuan</label><input id="unit" name="unit" value="{{ old('unit', 'porsi') }}" maxlength="20" required></div><div class="field"><label for="quantity">Stok siap jual hari ini</label><input id="quantity" name="quantity" type="number" min="0.001" max="999999999" step="0.001" value="{{ old('quantity') }}" required></div></div>
<p class="hint">Isi stok yang benar-benar tersedia. Stok awal dicatat dalam riwayat pergerakan stok.</p>
<div class="actions"><button>Simpan & periksa kesiapan</button></div>
@else
<h2>Data awal sudah lengkap</h2><ul class="review"><li><span class="badge">✓</span> Usaha: <strong>{{ $tenant->name }}</strong></li><li><span class="badge">✓</span> Cabang: <strong>{{ $store?->name }}</strong><br>{{ $store?->address }}</li><li><span class="badge">✓</span> Hak akses bawaan tersedia; akun Anda tetap Superadmin/Developer.</li><li><span class="badge">✓</span> Menu siap jual:@foreach($products as $product)<div class="product">{{ $product->name }} · Rp {{ number_format($product->selling_price, 0, ',', '.') }} / {{ $product->unit }}</div>@endforeach</li></ul>
<p>Setelah selesai, tambahkan menu, bahan baku, cabang, dan akun staf sesuai kebutuhan. Printer dan tampilan struk dapat diatur di Pengaturan.</p>
<label class="check"><input type="checkbox" name="confirm" value="1" required> Saya sudah memeriksa data awal dan siap menggunakan POS Warung.</label>
<div class="actions"><button>Selesai & masuk POS Warung</button></div>
@endif
</form></section>
@endif
</main></body></html>
