@extends('layouts.app')
@section('title', 'Pengeluaran')
@section('content')
<div class="page-head"><div><h1>Pengeluaran operasional</h1><p>Catat seluruh biaya operasional warung secara lengkap.</p></div><button class="btn btn-primary" onclick="openModal('expense-modal')"><i class="bi bi-wallet2"></i> Catat pengeluaran</button></div>
<div class="card card-pad"><div class="card-title"><div><h2>Riwayat pengeluaran</h2><p>{{ $expenses->total() }} catatan</p></div></div><div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Keterangan</th><th>Kategori</th><th>Nominal</th><th></th></tr></thead><tbody>
@forelse($expenses as $expense)<tr><td>{{ $expense->expense_date->format('d M Y') }}</td><td class="cell-main">{{ $expense->description }}</td><td><span class="badge gray">{{ $expense->category }}</span></td><td class="money">Rp {{ number_format($expense->amount,0,',','.') }}</td><td><form method="POST" action="{{ route('expenses.destroy',$expense) }}" onsubmit="return confirm('Arsipkan catatan ini?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Arsipkan</button></form></td></tr>@empty<tr><td colspan="5"><div class="cart-empty">Belum ada pengeluaran.</div></td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $expenses->links() }}</div></div>
@endsection
@push('modals')
<div class="modal" id="expense-modal"><div class="modal-card"><div class="modal-head"><h2>Catat pengeluaran</h2><button class="modal-close" onclick="closeModal('expense-modal')">×</button></div><form method="POST" action="{{ route('expenses.store') }}" class="form-grid">@csrf
<div class="field"><label>Kategori</label><select name="category" required>@foreach($categories as $category)<option value="{{ $category }}">{{ $category }}</option>@endforeach</select></div><div class="field"><label>Tanggal</label><input type="date" name="expense_date" value="{{ now()->toDateString() }}" required></div>
<div class="field full"><label>Keterangan</label><input name="description" required placeholder="Contoh: Belanja gas dapur"></div><div class="field full"><label>Nominal</label><input type="text" name="amount" data-money-input data-min="1" inputmode="numeric" autocomplete="off" placeholder="0" required></div>
<div class="field full"><button class="btn btn-primary">Simpan pengeluaran</button></div></form></div></div>
@endpush
