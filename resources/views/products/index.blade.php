@extends('layouts.app')
@section('title', 'Produk')
@section('content')
<div class="page-head"><div><h1>Daftar produk</h1><p>Kelola harga, kategori, barcode, dan stok minimum.</p></div><button class="btn btn-primary" onclick="openModal('product-modal')"><i class="bi bi-plus-circle"></i> Tambah produk</button></div>
<div class="card card-pad">
    <div class="card-title"><div><h2>{{ $products->total() }} produk aktif</h2><p>Stok mengikuti cabang aktif</p></div><div class="search" style="max-width:280px"><input placeholder="Cari produk…" oninput="filterRows(this.value)"></div></div>
    <div class="table-wrap"><table id="product-table"><thead><tr><th>Produk</th><th>Jenis</th><th>Kategori</th><th>Harga beli</th><th>Harga jual</th><th>Stok</th><th></th></tr></thead><tbody>
    @forelse($products as $product)
        @php($currentStock = $product->product_type === 'menu' ? ($product->daily_stock ?? 0) : ($product->warehouse_stock ?? 0))
        <tr><td><div class="cell-main">{{ $product->name }}</div><div class="cell-sub">{{ $product->sku }} · {{ $product->barcode ?: 'Tanpa barcode' }}</div></td><td><span class="badge {{ $product->product_type === 'ingredient' ? 'amber' : '' }}">{{ $product->product_type === 'menu' ? 'Menu harian' : 'Bahan baku' }}</span></td><td><span class="badge gray">{{ $product->category?->name ?? 'Umum' }}</span></td><td>Rp {{ number_format($product->purchase_price,0,',','.') }}</td><td class="money">{{ $product->product_type === 'menu' ? 'Rp '.number_format($product->selling_price,0,',','.') : '—' }}</td><td><span class="badge {{ $currentStock <= $product->minimum_stock ? 'amber' : '' }}">{{ $currentStock }} {{ $product->unit }}</span></td><td><div class="actions"><button class="btn btn-outline btn-sm edit-product" data-id="{{ $product->id }}" data-name="{{ $product->name }}" data-sku="{{ $product->sku }}" data-barcode="{{ $product->barcode }}" data-category="{{ $product->category_id }}" data-unit="{{ $product->unit }}" data-buy="{{ $product->purchase_price }}" data-sell="{{ $product->selling_price }}" data-minimum="{{ $product->minimum_stock }}"><i class="bi bi-pencil"></i> Edit</button><form method="POST" action="{{ route('products.destroy',$product) }}" onsubmit="return confirm('Arsipkan produk ini?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><i class="bi bi-archive"></i></button></form></div></td></tr>
    @empty<tr><td colspan="7"><div class="cart-empty">Belum ada produk.</div></td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination">{{ $products->links() }}</div>
</div>
@endsection
@push('modals')
<div class="modal" id="product-modal"><div class="modal-card"><div class="modal-head"><div><h2>Tambah produk baru</h2><div class="hint">Produk akan tersedia di kasir cabang aktif.</div></div><button class="modal-close" onclick="closeModal('product-modal')">×</button></div>
<form method="POST" action="{{ route('products.store') }}" class="form-grid">@csrf
    <div class="field full"><label>Nama produk</label><input name="name" required placeholder="Contoh: Kopi Susu Gula Aren"></div>
    <div class="field full"><label>Jenis persediaan</label><select name="product_type" required><option value="menu">Menu siap jual · stok harian</option><option value="ingredient">Bahan baku · stok gudang</option></select></div>
    <div class="field"><label>SKU</label><input name="sku" required placeholder="KOPI-001"></div>
    <div class="field"><label>Barcode</label><input name="barcode" placeholder="Opsional"></div>
    <div class="field"><label>Kategori</label><select name="category_id"><option value="">Umum</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
    <div class="field"><label>Satuan</label><select name="unit"><option>pcs</option><option>botol</option><option>bungkus</option><option>kg</option><option>porsi</option></select></div>
    <div class="field"><label>Harga beli</label><input type="text" name="purchase_price" data-money-input data-min="0" inputmode="numeric" autocomplete="off" placeholder="0" required></div>
    <div class="field"><label>Harga jual</label><input type="text" name="selling_price" data-money-input data-min="0" inputmode="numeric" autocomplete="off" placeholder="0" required></div>
    <div class="field"><label>Stok awal</label><input type="number" name="initial_stock" value="0" min="0"></div>
    <div class="field"><label>Stok minimum</label><input type="number" name="minimum_stock" value="5" min="0" required></div>
    <div class="field full"><button class="btn btn-primary"><i class="bi bi-check-circle"></i> Simpan produk</button></div>
</form></div></div>
<div class="modal" id="edit-product-modal"><div class="modal-card"><div class="modal-head"><h2>Edit produk</h2><button class="modal-close" onclick="closeModal('edit-product-modal')">×</button></div>
<form method="POST" id="edit-product-form" class="form-grid">@csrf @method('PUT')
    <div class="field full"><label>Nama produk</label><input id="edit-name" name="name" required></div>
    <div class="field"><label>SKU</label><input id="edit-sku" name="sku" required></div><div class="field"><label>Barcode</label><input id="edit-barcode" name="barcode"></div>
    <div class="field"><label>Kategori</label><select id="edit-category" name="category_id"><option value="">Umum</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
    <div class="field"><label>Satuan</label><input id="edit-unit" name="unit" required></div><div class="field"><label>Harga beli</label><input id="edit-buy" type="text" name="purchase_price" data-money-input data-min="0" inputmode="numeric" autocomplete="off" required></div>
    <div class="field"><label>Harga jual</label><input id="edit-sell" type="text" name="selling_price" data-money-input data-min="0" inputmode="numeric" autocomplete="off" required></div><div class="field"><label>Stok minimum</label><input id="edit-minimum" type="number" name="minimum_stock" min="0" required></div>
    <div class="field full"><button class="btn btn-primary">Simpan perubahan</button></div>
</form></div></div>
@endpush
@push('scripts')<script>
function filterRows(q){document.querySelectorAll('#product-table tbody tr').forEach(r=>r.style.display=r.innerText.toLowerCase().includes(q.toLowerCase())?'':'none')}
document.querySelectorAll('.edit-product').forEach(button=>button.addEventListener('click',()=>{const d=button.dataset;document.getElementById('edit-product-form').action='/produk/'+d.id;['name','sku','barcode','unit','buy','sell','minimum','category'].forEach(k=>document.getElementById('edit-'+k).value=d[k]||'');setMoneyInputValue(document.getElementById('edit-buy'),d.buy);setMoneyInputValue(document.getElementById('edit-sell'),d.sell);openModal('edit-product-modal')}));
</script>@endpush
