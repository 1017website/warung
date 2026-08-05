@extends('layouts.app')
@section('title', 'Ringkasan')
@section('content')
<div class="page-head">
    <div><h1>Selamat {{ now()->hour < 12 ? 'pagi' : (now()->hour < 17 ? 'siang' : 'sore') }}, {{ str(auth()->user()->name)->before(' ') }}!</h1><p>Berikut kondisi {{ $isConsolidated ? 'consolidated seluruh warung' : $activeStore->name }} hari ini.</p></div>
    @if(in_array(auth()->user()->role, ['superadmin','owner','admin','cashier']))<a class="btn btn-primary" href="{{ route('pos') }}"><i class="bi bi-calculator"></i> Buka kasir</a>@endif
</div>
<div class="grid stats">
    <div class="card stat"><span class="stat-icon"><i class="bi bi-graph-up-arrow"></i></span><div class="stat-label">Penjualan hari ini</div><div class="stat-value">Rp {{ number_format($sales, 0, ',', '.') }}</div><div class="stat-note">{{ $transactionCount }} transaksi selesai</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-receipt"></i></span><div class="stat-label">Rata-rata transaksi</div><div class="stat-value">Rp {{ number_format($transactionCount ? $sales / $transactionCount : 0, 0, ',', '.') }}</div><div class="stat-note">Nilai per struk</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-wallet2"></i></span><div class="stat-label">Pengeluaran hari ini</div><div class="stat-value">Rp {{ number_format($expenses, 0, ',', '.') }}</div><div class="stat-note">Tercatat & terpantau</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-exclamation-circle"></i></span><div class="stat-label">Stok perlu perhatian</div><div class="stat-value">{{ $lowStocks->count() }} produk</div><div class="stat-note">{{ $lowStocks->count() ? 'Segera lakukan restok' : 'Semua stok aman' }}</div></div>
</div>
<div class="grid two-col">
    <section class="card card-pad">
        <div class="card-title"><div><h2><i class="bi bi-bar-chart-line"></i> Tren penjualan 7 hari</h2><p>Omzet kotor per hari</p></div><span class="badge">7 hari terakhir</span></div>
        @php($max = max(1, $week->max('value')))
        <div class="chart">@foreach($week as $day)<div class="bar-col" title="Rp {{ number_format($day['value'],0,',','.') }}"><div class="bar" style="height: {{ max(3, ($day['value']/$max)*88) }}%"></div><span>{{ $day['label'] }}</span></div>@endforeach</div>
    </section>
    <section class="card card-pad">
        <div class="card-title"><div><h2><i class="bi bi-boxes"></i> Perhatian stok</h2><p>Di bawah batas minimum</p></div><a href="{{ route('inventory') }}" class="badge gray">Lihat semua</a></div>
        <div class="list">
            @forelse($lowStocks as $stock)
                <div class="list-item"><span class="list-icon">{{ mb_substr($stock->product->name,0,2) }}</span><div class="list-body"><div class="list-title">{{ $stock->product->name }}</div><div class="list-sub">{{ $isConsolidated ? ($stock->store?->name.' · ') : '' }}Minimum {{ $stock->product->minimum_stock }} {{ $stock->product->unit }}</div></div><span class="badge {{ $stock->quantity <= 0 ? 'red' : 'amber' }}">{{ $stock->quantity }} {{ $stock->product->unit }}</span></div>
            @empty
                <div class="cart-empty">Stok dalam kondisi aman.</div>
            @endforelse
        </div>
    </section>
    <section class="card card-pad" style="grid-column:1/-1">
        <div class="card-title"><div><h2>Transaksi terbaru</h2><p>Aktivitas penjualan hari ini</p></div><a href="{{ route('transactions') }}" class="btn btn-outline btn-sm">Semua transaksi</a></div>
        <div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Waktu</th><th>Member</th><th>Pembayaran</th><th>Total</th></tr></thead><tbody>
        @forelse($latest as $trx)<tr><td><div class="cell-main">{{ $trx->invoice_no }}</div>@if($isConsolidated)<div class="cell-sub">{{ $trx->store?->name }}</div>@endif</td><td>{{ $trx->transacted_at->format('H:i') }}</td><td>{{ $trx->member?->name ?? 'Umum' }}</td><td><span class="badge gray">{{ strtoupper($trx->payment_method) }}</span></td><td class="money">Rp {{ number_format($trx->total,0,',','.') }}</td></tr>
        @empty<tr><td colspan="5"><div class="cart-empty">Belum ada transaksi hari ini.</div></td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
