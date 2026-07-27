@extends('layouts.app')
@section('title', 'Pembelian')
@section('content')
<div class="page-head"><div><h1>Pembelian stok</h1><p>Penerimaan pembelian langsung menambah stok gudang.</p></div><button class="btn btn-primary" onclick="openModal('purchase-modal')"><i class="bi bi-bag-plus"></i> Catat pembelian</button></div>
<div class="card card-pad"><div class="card-title"><div><h2>Riwayat pembelian</h2><p>Cabang {{ $activeStore->name }}</p></div></div><div class="table-wrap"><table><thead><tr><th>No. pembelian</th><th>Tanggal</th><th>Supplier</th><th>Item</th><th>Status</th><th>Total</th></tr></thead><tbody>
@forelse($purchases as $purchase)<tr><td class="cell-main">{{ $purchase->purchase_no }}</td><td>{{ $purchase->purchased_at->format('d M Y') }}</td><td>{{ $purchase->supplier_name }}</td><td>{{ $purchase->items->sum('quantity') }} unit</td><td><span class="badge">Diterima</span></td><td class="money">Rp {{ number_format($purchase->total,0,',','.') }}</td></tr>@empty<tr><td colspan="6"><div class="cart-empty">Belum ada pembelian.</div></td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $purchases->links() }}</div></div>
@endsection
@push('modals')
<div class="modal" id="purchase-modal"><div class="modal-card"><div class="modal-head"><div><h2>Catat pembelian</h2><div class="hint">Stok otomatis bertambah setelah disimpan.</div></div><button class="modal-close" onclick="closeModal('purchase-modal')">×</button></div><form method="POST" action="{{ route('purchases.store') }}" class="form-grid">@csrf
<div class="field full"><label>Supplier</label><input name="supplier_name" required placeholder="Nama pemasok"></div><div class="field full"><label>Produk</label><select name="product_id" required>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div>
<div class="field"><label>Jumlah</label><input type="number" name="quantity" min="1" required></div><div class="field"><label>Harga beli / unit</label><input type="number" name="unit_cost" min="0" required></div>
<div class="field"><label>Tanggal pembelian</label><input type="date" name="purchased_at" value="{{ now()->toDateString() }}" required></div><div class="field"><label>Catatan</label><input name="notes" placeholder="Opsional"></div>
<div class="field full"><button class="btn btn-primary">Terima & tambah stok</button></div></form></div></div>
@endpush
