@extends('layouts.app')
@section('title', 'Tutup Kasir')
@section('content')
<div class="page-head">
    <div><h1>Rekonsiliasi kas harian</h1><p>{{ $activeStore->name }} · {{ today()->translatedFormat('d F Y') }}</p></div>
    <button class="btn btn-outline" onclick="window.print()"><i class="bi bi-printer"></i> Cetak rekap</button>
</div>
<div class="grid stats">
    <div class="card stat"><div class="stat-label">Transaksi selesai</div><div class="stat-value">{{ $summary['transactions'] }}</div></div>
    <div class="card stat"><div class="stat-label">Tunai penjualan (net)</div><div class="stat-value">Rp {{ number_format($summary['cashSales'],0,',','.') }}</div></div>
    <div class="card stat"><div class="stat-label">Top up tunai</div><div class="stat-value">Rp {{ number_format($summary['cashTopups'],0,',','.') }}</div></div>
    <div class="card stat"><div class="stat-label">Pengeluaran tunai</div><div class="stat-value">Rp {{ number_format($summary['cashExpenses'],0,',','.') }}</div></div>
</div>
<div class="grid two-col" style="margin-top:16px">
    <section class="card card-pad">
        <div class="card-title"><div><h2>Hitung kas fisik</h2><p>Kas seharusnya = modal awal + penjualan tunai + top up tunai − pengeluaran tunai.</p></div></div>
        <form method="POST" action="{{ route('pos.close.store') }}" class="form-grid">
            @csrf
            <div class="field"><label>Modal kas awal</label><input type="text" name="opening_cash" data-money-input data-min="0" value="{{ number_format(old('opening_cash',$closing?->opening_cash ?? 0),0,',','.') }}" required></div>
            <div class="field"><label>Kas fisik saat ditutup</label><input type="text" name="actual_cash" data-money-input data-min="0" value="{{ number_format(old('actual_cash',$closing?->actual_cash ?? 0),0,',','.') }}" required></div>
            @unless(auth()->user()->isSupervisor())
                <div class="field full"><label>PIN Manager/SPV cabang aktif</label><input type="password" name="approval_pin" inputmode="numeric" maxlength="12" required></div>
            @endunless
            <div class="field full"><label>Catatan selisih / serah terima</label><textarea name="notes" rows="3">{{ old('notes',$closing?->notes) }}</textarea></div>
            <div class="field full"><button class="btn btn-primary">{{ $closing ? 'Perbarui tutup kasir' : 'Simpan tutup kasir' }}</button></div>
        </form>
        @if($closing)
            <div class="alert {{ (float)$closing->difference === 0.0 ? 'alert-success' : 'alert-error' }}" style="margin-top:16px">
                Kas seharusnya <b>Rp {{ number_format($closing->expected_cash,0,',','.') }}</b>, kas fisik <b>Rp {{ number_format($closing->actual_cash,0,',','.') }}</b>, selisih <b>Rp {{ number_format($closing->difference,0,',','.') }}</b>.<br>
                Disimpan {{ $closing->closed_at->format('H:i') }} oleh {{ $closing->user?->name }} · otorisasi {{ $closing->authorizer?->name }}.
            </div>
        @endif
    </section>
    <section class="card card-pad">
        <div class="card-title"><div><h2>Rincian pembayaran</h2><p>Nominal tunai sudah dikurangi kembalian.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Kanal</th><th>Provider</th><th>Net diterima</th></tr></thead><tbody>
        @forelse($summary['paymentSummary'] as $payment)
            <tr><td>{{ strtoupper($payment['method']) }}</td><td>{{ $payment['provider'] ?: '—' }}</td><td class="money">Rp {{ number_format($payment['total'],0,',','.') }}</td></tr>
        @empty<tr><td colspan="3">Belum ada pembayaran hari ini.</td></tr>@endforelse
        </tbody></table></div>
        <div class="card-title" style="margin-top:22px"><div><h2>Produk terjual</h2><p>Rekap kuantitas transaksi selesai.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Produk</th><th>Qty</th></tr></thead><tbody>
        @forelse($sales as $item)<tr><td>{{ $item->product_name }}</td><td>{{ $item->quantity }} {{ $item->unit }}</td></tr>
        @empty<tr><td colspan="2">Belum ada produk terjual.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
