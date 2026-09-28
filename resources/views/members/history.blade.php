@extends('layouts.app')
@section('title', 'Riwayat Member')
@section('content')
@php($query = array_filter(['from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'type' => request('type')]))
<div class="page-head">
    <div>
        <h1><i class="bi bi-clock-history"></i> Riwayat {{ $member->name }}</h1>
        <p>{{ $member->member_code }} · {{ $member->phone ?: 'Tanpa nomor HP' }} · {{ $member->is_active ? 'Aktif' : 'Nonaktif' }}. {{ $allStores ? 'Aktivitas di seluruh warung.' : 'Aktivitas di '.auth()->user()->store?->name.'; saldo berlaku di semua warung.' }}</p>
    </div>
    <div class="actions">
        <a class="btn btn-outline" href="{{ route('members') }}"><i class="bi bi-arrow-left"></i> Kembali</a>
        <a class="btn btn-primary" href="{{ route('members.history.export', ['member' => $member] + $query) }}"><i class="bi bi-file-earmark-excel"></i> Download Excel</a>
    </div>
</div>

<div class="grid stats">
    <div class="card stat"><span class="stat-icon"><i class="bi bi-wallet2"></i></span><div class="stat-label">Saldo deposit</div><div class="stat-value">Rp {{ number_format($member->deposit_balance,0,',','.') }}</div><div class="stat-note">Berlaku di semua warung</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-bag-check"></i></span><div class="stat-label">Total belanja</div><div class="stat-value">Rp {{ number_format($summary['spent'],0,',','.') }}</div><div class="stat-note">{{ $summary['visits'] }} transaksi selesai</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-plus-circle"></i></span><div class="stat-label">Total top up</div><div class="stat-value">Rp {{ number_format($summary['topups'],0,',','.') }}</div><div class="stat-note">Deposit terpakai Rp {{ number_format($summary['deposit_used'],0,',','.') }}</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-calendar-check"></i></span><div class="stat-label">Kunjungan terakhir</div><div class="stat-value" style="font-size:18px">{{ $summary['last_visit'] ? \Carbon\Carbon::parse($summary['last_visit'])->translatedFormat('d M Y') : '—' }}</div><div class="stat-note">Diskon member {{ number_format($member->discount_percent,1,',','.') }}%</div></div>
</div>

<div class="card card-pad" style="margin-bottom:16px">
    <form class="history-filter" method="GET">
        <div class="field"><label>Dari</label><input type="date" name="from" value="{{ $from?->toDateString() }}"></div>
        <div class="field"><label>Sampai</label><input type="date" name="to" value="{{ $to?->toDateString() }}"></div>
        <div class="field"><label>Mutasi deposit</label><select name="type"><option value="all">Semua</option><option value="credit" @selected(request('type')==='credit')>Masuk (top up / refund)</option><option value="debit" @selected(request('type')==='debit')>Keluar (pembayaran)</option></select></div>
        <div class="field" style="align-self:end"><button class="btn btn-soft"><i class="bi bi-funnel"></i> Terapkan</button></div>
        @if($query)<div class="field" style="align-self:end"><a class="btn btn-outline" href="{{ route('members.history',$member) }}">Reset</a></div>@endif
    </form>
</div>

<section class="card card-pad">
    <div class="card-title"><div><h2><i class="bi bi-arrow-left-right"></i> Mutasi deposit</h2><p>{{ $mutations->total() }} catatan · top up, pembayaran, refund, dan koreksi</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Keterangan</th><th>Cabang / petugas</th><th>Metode</th><th>Nominal</th><th>Saldo setelah</th></tr></thead><tbody>
    @forelse($mutations as $mutation)
        <tr>
            <td><div>{{ \Carbon\Carbon::parse($mutation->created_at)->translatedFormat('d M Y') }}</div><div class="cell-sub">{{ \Carbon\Carbon::parse($mutation->created_at)->format('H:i') }}</div></td>
            <td><div class="cell-main">{{ $mutation->description }}</div>@if($mutation->invoice_no)<div class="cell-sub">{{ $mutation->invoice_no }}</div>@endif</td>
            <td><div>{{ $mutation->store_name ?? '—' }}</div><div class="cell-sub">{{ $mutation->user_name ?? '—' }}</div></td>
            <td><span class="badge gray">{{ strtoupper($mutation->payment_method ?: 'cash') }}</span></td>
            <td class="money {{ $mutation->type==='credit' ? 'amount-in' : 'amount-out' }}">{{ $mutation->type==='credit' ? '+' : '−' }}Rp {{ number_format($mutation->amount,0,',','.') }}</td>
            <td class="money">Rp {{ number_format($mutation->balance_after,0,',','.') }}</td>
        </tr>
    @empty
        <tr><td colspan="6"><div class="cart-empty">Belum ada mutasi deposit pada periode ini.</div></td></tr>
    @endforelse
    </tbody></table></div>
    <div class="pagination">{{ $mutations->links() }}</div>
</section>

<section class="card card-pad" style="margin-top:16px">
    <div class="card-title"><div><h2><i class="bi bi-receipt"></i> Transaksi member</h2><p>{{ $transactions->total() }} transaksi selesai & dibatalkan</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Invoice & waktu</th><th>Cabang / kasir</th><th>Pesanan</th><th>Pembayaran</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($transactions as $transaction)
        <tr>
            <td><div class="cell-main">{{ $transaction->invoice_no }}</div><div class="cell-sub">{{ $transaction->transacted_at->translatedFormat('d M Y, H:i') }}</div></td>
            <td><div>{{ $transaction->store?->name }}</div><div class="cell-sub">{{ $transaction->user?->name }}</div></td>
            <td><div class="report-items">@foreach($transaction->items as $item)<span class="report-item">{{ $item->product_name }} <b>×@qty($item->quantity)</b></span>@endforeach</div></td>
            <td>@forelse($transaction->payments as $pay)<span class="badge gray">{{ strtoupper($pay->method) }}{{ $pay->provider ? ' · '.$pay->provider : '' }}</span>@empty<span class="badge gray">{{ strtoupper($transaction->payment_method) }}</span>@endforelse</td>
            <td class="money">Rp {{ number_format($transaction->total,0,',','.') }}</td>
            <td><span class="badge {{ $transaction->status==='voided' ? 'red' : '' }}">{{ $transaction->status==='voided' ? 'Dibatalkan' : ($transaction->transaction_type==='replacement' ? 'Retur' : 'Selesai') }}</span></td>
            <td>@if($transaction->status==='completed' && collect(['transactions','pos','reports'])->contains(fn($module)=>auth()->user()->canAccess($module)))<a class="btn btn-outline btn-sm" href="{{ route('transactions.print',$transaction) }}" target="_blank" title="Lihat struk"><i class="bi bi-printer"></i></a>@endif</td>
        </tr>
    @empty
        <tr><td colspan="7"><div class="cart-empty">Belum ada transaksi pada periode ini.</div></td></tr>
    @endforelse
    </tbody></table></div>
    <div class="pagination">{{ $transactions->links() }}</div>
</section>
@endsection
