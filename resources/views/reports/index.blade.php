@extends('layouts.app')
@section('title', 'Laporan')
@section('content')
<div class="page-head"><div><h1>Laporan usaha</h1><p>{{ $canSeeNonReal ? 'Laporan non-riil dihitung otomatis sebesar 50% dari laporan riil.' : 'Ringkasan kinerja usaha pada periode terpilih.' }}</p></div><div class="actions">@if($canSeeNonReal)<div class="report-tabs"><a class="{{ $type === 'real' ? 'active' : '' }}" href="{{ route('reports',['type'=>'real','period'=>$period,'from'=>$from->toDateString(),'to'=>$to->toDateString()]) }}">Laporan riil</a><a class="{{ $type === 'non_real' ? 'active' : '' }}" href="{{ route('reports',['type'=>'non_real','period'=>$period,'from'=>$from->toDateString(),'to'=>$to->toDateString()]) }}">Non-riil</a></div>@endif<a class="btn btn-primary" href="{{ route('reports.export',['type'=>$type,'period'=>$period,'from'=>$from->toDateString(),'to'=>$to->toDateString()]) }}"><i class="bi bi-file-earmark-excel"></i> Unduh Excel</a></div></div>
<div class="card card-pad report-filter-card">
    <div class="quick-periods">
        <a class="{{ $period === 'today' ? 'active' : '' }}" href="{{ route('reports',['type'=>$type,'period'=>'today']) }}"><i class="bi bi-calendar-event"></i> Hari ini</a>
        <a class="{{ $period === 'week' ? 'active' : '' }}" href="{{ route('reports',['type'=>$type,'period'=>'week']) }}"><i class="bi bi-calendar-week"></i> Minggu ini</a>
        <a class="{{ $period === 'month' ? 'active' : '' }}" href="{{ route('reports',['type'=>$type,'period'=>'month']) }}"><i class="bi bi-calendar3"></i> Bulan ini</a>
        <a class="{{ $period === 'year' ? 'active' : '' }}" href="{{ route('reports',['type'=>$type,'period'=>'year']) }}"><i class="bi bi-calendar-range"></i> Tahun ini</a>
    </div>
    <form class="custom-period-form" method="GET"><input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="period" value="custom"><div class="field"><label>Dari tanggal</label><input type="date" name="from" value="{{ $from->toDateString() }}"></div><div class="field"><label>Sampai tanggal</label><input type="date" name="to" value="{{ $to->toDateString() }}"></div><div class="field" style="align-self:end"><button class="btn {{ $period === 'custom' ? 'btn-primary' : 'btn-soft' }}"><i class="bi bi-funnel"></i> Tanggal khusus</button></div></form>
</div>
<div class="grid stats">
    <div class="card stat"><span class="stat-icon"><i class="bi bi-graph-up"></i></span><div class="stat-label">Penjualan</div><div class="stat-value">Rp {{ number_format($sales,0,',','.') }}</div><div class="stat-note">{{ $canSeeNonReal ? ($type === 'real' ? 'Laporan riil' : 'Laporan non-riil · 50%') : 'Periode terpilih' }}</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-box-seam"></i></span><div class="stat-label">Harga pokok</div><div class="stat-value">Rp {{ number_format($cost,0,',','.') }}</div><div class="stat-note">Modal barang terjual</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-wallet2"></i></span><div class="stat-label">Pengeluaran</div><div class="stat-value">Rp {{ number_format($expenses,0,',','.') }}</div><div class="stat-note">Biaya operasional</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-piggy-bank"></i></span><div class="stat-label">Laba bersih</div><div class="stat-value">Rp {{ number_format($profit,0,',','.') }}</div><div class="stat-note">{{ $sales ? number_format(($profit/$sales)*100,1) : 0 }}% margin</div></div>
</div>
<div class="grid two-col">
    <section class="card card-pad">
        <div class="card-title"><div><h2>Penjualan harian</h2><p>{{ $from->translatedFormat('d M') }} – {{ $to->translatedFormat('d M Y') }}</p></div></div>
        @php
            $maxDaily = max(1, $daily->max('total') ?? 1);
        @endphp
        <div class="chart">
            @forelse($daily as $d)
                <div class="bar-col" title="Rp {{ number_format($d->total,0,',','.') }}"><div class="bar" style="height:{{ max(3,($d->total/$maxDaily)*88) }}%"></div><span>{{ \Carbon\Carbon::parse($d->date)->format('d/m') }}</span></div>
            @empty
                <div class="cart-empty" style="width:100%">Tidak ada data pada periode ini.</div>
            @endforelse
        </div>
    </section>
    <section class="card card-pad">
        <div class="card-title"><div><h2>Metode pembayaran</h2><p>Kontribusi terhadap omzet</p></div></div>
        <div class="list">
            @forelse($payments as $pay)
                <div class="list-item"><span class="list-icon">{{ strtoupper(substr($pay->payment_method,0,2)) }}</span><div class="list-body"><div class="list-title">{{ strtoupper($pay->payment_method) }}</div><div class="list-sub">{{ $pay->count }} transaksi</div></div><span class="money">Rp {{ number_format($pay->total,0,',','.') }}</span></div>
            @empty
                <div class="cart-empty">Belum ada data pembayaran.</div>
            @endforelse
        </div>
    </section>
</div>
<section class="card card-pad" style="margin-top:16px">
    <div class="card-title">
        <div><h2><i class="bi bi-receipt-cutoff"></i> Detail transaksi</h2><p>{{ $transactionRows->count() }} transaksi pada periode terpilih</p></div>
        <div class="search" style="max-width:290px"><input id="report-transaction-search" placeholder="Cari invoice, kasir, atau menu…"></div>
    </div>
    <div class="table-wrap">
        <table id="report-transaction-table">
            <thead><tr><th>Invoice & waktu</th><th>Jenis pesanan</th><th>Kasir / member</th><th>Detail pesanan</th><th>Pembayaran</th><th>Omzet</th><th>HPP</th><th>Laba kotor</th><th></th></tr></thead>
            <tbody>
            @forelse($transactionRows as $transaction)
                @php
                    $rowCost = $transaction->items->sum(fn($item) => $item->cost * $item->quantity) * $factor;
                    $rowSales = $transaction->total * $factor;
                    $rowProfit = $rowSales - $rowCost;
                @endphp
                <tr>
                    <td><div class="cell-main">{{ $transaction->invoice_no }}</div><div class="cell-sub">{{ $transaction->transacted_at->translatedFormat('d M Y, H:i') }}</div></td>
                    <td>
                        <div class="cell-main">{{ match($transaction->service_type) {'takeaway' => 'Take away', 'online' => 'Ojek online', default => 'Dine in'} }}</div>
                        <div class="cell-sub">{{ $transaction->service_type === 'dine_in' ? 'Meja '.($transaction->table_number ?: '—') : ($transaction->service_type === 'online' ? ($transaction->online_platform ?: 'Platform online') : 'Dibawa pulang') }}</div>
                    </td>
                    <td><div class="cell-main">{{ $transaction->user->name }}</div><div class="cell-sub">{{ $transaction->member?->name ?? 'Pelanggan umum' }}</div></td>
                    <td><div class="report-items">@foreach($transaction->items as $item)<span class="report-item">{{ $item->product_name }} <b>×{{ $item->quantity }}</b></span>@endforeach</div></td>
                    <td><span class="badge gray">{{ strtoupper($transaction->payment_method) }}</span></td>
                    <td class="money">Rp {{ number_format($rowSales,0,',','.') }}</td>
                    <td class="money">Rp {{ number_format($rowCost,0,',','.') }}</td>
                    <td class="money report-profit">Rp {{ number_format($rowProfit,0,',','.') }}</td>
                    <td>@if($type === 'real')<a class="btn btn-outline btn-sm" href="{{ route('transactions.print',$transaction) }}" target="_blank" title="Buka struk asli"><i class="bi bi-printer"></i></a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="cart-empty">Belum ada transaksi pada periode ini.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
@push('scripts')
<script>
document.getElementById('report-transaction-search')?.addEventListener('input', event => {
    const query = event.target.value.toLowerCase();
    document.querySelectorAll('#report-transaction-table tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(query) ? '' : 'none';
    });
});
</script>
@endpush
