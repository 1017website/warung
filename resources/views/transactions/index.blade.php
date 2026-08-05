@extends('layouts.app')
@section('title', 'Transaksi')
@section('content')
<div class="page-head"><div><h1>Riwayat transaksi</h1><p>Transaksi tidak dihapus sebagai “arsip”; pembatalan disimpan sebagai audit lengkap dengan alasan dan pemberi ACC.</p></div><a class="btn btn-primary" href="{{ route('pos') }}"><i class="bi bi-plus-circle"></i> Transaksi baru</a></div>
<div class="card card-pad">
    <div class="card-title"><div><h2>{{ $transactions->total() }} transaksi</h2><p>Cabang {{ $activeStore->name }}</p></div><div class="actions"><div class="report-tabs"><a class="{{ !request('status') ? 'active' : '' }}" href="{{ route('transactions') }}">Semua</a><a class="{{ request('status')==='pending' ? 'active' : '' }}" href="{{ route('transactions',['status'=>'pending']) }}">Pending</a><a class="{{ request('status')==='voided' ? 'active' : '' }}" href="{{ route('transactions',['status'=>'voided']) }}">Dibatalkan</a></div><div class="search" style="max-width:240px"><input placeholder="Cari invoice…" oninput="filterTransactions(this.value)"></div></div></div>
    <div class="table-wrap"><table id="trx-table"><thead><tr><th>Invoice</th><th>Waktu</th><th>Pesanan</th><th>Kasir / member</th><th>Pembayaran detail</th><th>Total</th><th>Status / aksi</th></tr></thead><tbody>
    @forelse($transactions as $trx)
        <tr>
            <td><div class="cell-main">{{ $trx->invoice_no }}</div><div class="cell-sub">{{ $trx->transaction_type === 'replacement' ? 'Retur / pengganti' : 'Penjualan' }}</div></td>
            <td><div>{{ $trx->transacted_at->format('d M Y') }}</div><div class="cell-sub">{{ $trx->transacted_at->format('H:i:s') }}</div></td>
            <td><div class="cell-main">{{ match($trx->service_type) {'takeaway'=>'Take away','online'=>'Ojek online',default=>'Dine in'} }}</div><div class="cell-sub">{{ $trx->service_type==='dine_in'?'Meja '.($trx->table_number?:'—'):($trx->online_platform?:'Dibawa pulang') }}</div></td>
            <td><div>{{ $trx->user->name }}</div><div class="cell-sub">{{ $trx->member?->name ?? 'Pelanggan umum' }}</div></td>
            <td>
                @if($trx->payments->isNotEmpty())
                    @foreach($trx->payments as $pay)<div><span class="badge gray">{{ strtoupper($pay->method) }}</span> {{ $pay->provider ? $pay->provider.' · ' : '' }}Rp {{ number_format($pay->amount,0,',','.') }}</div>@endforeach
                @else <span class="badge gray">{{ strtoupper($trx->payment_method) }}</span> @endif
            </td>
            <td class="money">Rp {{ number_format($trx->total,0,',','.') }}</td>
            <td>
                <div class="actions">
                    <span class="badge {{ $trx->status==='voided'?'red':($trx->status==='pending'?'amber':'') }}">{{ ['completed'=>'Selesai','pending'=>'Pending','voided'=>'Dibatalkan'][$trx->status] ?? $trx->status }}</span>
                    @if($trx->status==='completed')<a class="btn btn-outline btn-sm" href="{{ route('transactions.print',$trx) }}" target="_blank"><i class="bi bi-printer"></i></a><button class="btn btn-danger btn-sm cancel-trigger" data-url="{{ route('transactions.destroy',$trx) }}" data-invoice="{{ $trx->invoice_no }}" data-age="{{ $trx->transacted_at->diffInSeconds(now()) }}"><i class="bi bi-x-octagon"></i></button>@endif
                </div>
                @if($trx->status==='voided')<div class="cell-sub" style="margin-top:5px">{{ $trx->cancel_reason }} · ACC {{ $trx->voidAuthorizer?->name ?? '—' }}</div>@endif
            </td>
        </tr>
    @empty<tr><td colspan="7"><div class="cart-empty">Belum ada transaksi.</div></td></tr>@endforelse
    </tbody></table></div><div class="pagination">{{ $transactions->links() }}</div>
</div>
@endsection
@push('modals')
<div class="modal" id="cancel-modal"><div class="modal-card" style="max-width:480px"><div class="modal-head"><div><h2>Batalkan transaksi</h2><div id="cancel-invoice" class="hint"></div></div><button class="modal-close" onclick="closeModal('cancel-modal')">×</button></div><form id="cancel-form" method="POST" class="form-grid">@csrf @method('DELETE')<div class="field full"><label>Alasan pembatalan</label><textarea name="reason" minlength="5" required placeholder="Wajib diisi untuk audit"></textarea></div><div class="field full"><label>PIN Manager/SPV <small id="pin-help"></small></label><input name="approval_pin" type="password" inputmode="numeric" maxlength="12"><div class="hint">Wajib bila transaksi sudah lewat 30 detik.</div></div><div class="field full"><button class="btn btn-danger">Konfirmasi pembatalan</button></div></form></div></div>
@endpush
@push('scripts')<script>function filterTransactions(q){document.querySelectorAll('#trx-table tbody tr').forEach(r=>r.style.display=r.innerText.toLowerCase().includes(q.toLowerCase())?'':'none')}document.querySelectorAll('.cancel-trigger').forEach(button=>button.addEventListener('click',()=>{document.getElementById('cancel-form').action=button.dataset.url;document.getElementById('cancel-invoice').textContent=button.dataset.invoice;document.getElementById('pin-help').textContent=Number(button.dataset.age)>30?'(wajib)':'(belum wajib, transaksi ≤30 detik)';openModal('cancel-modal')}));</script>@endpush
