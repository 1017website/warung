@extends('layouts.app')
@section('title', 'Stok / Gudang')
@section('content')
<div class="page-head">
    <div><h1><i class="bi bi-box-seam"></i> Stok & gudang</h1><p>Bahan baku tersimpan di gudang, sedangkan stok makanan diatur ulang setiap hari.</p></div>
    <button class="btn btn-primary" onclick="openModal('adjust-modal')"><i class="bi bi-sliders2"></i> Penyesuaian stok</button>
</div>

<div class="inventory-tabs" role="tablist">
    <button class="inventory-tab active" data-target="ingredients-panel"><i class="bi bi-boxes"></i><span><b>Gudang bahan baku</b><small>Stok berkelanjutan</small></span></button>
    <button class="inventory-tab" data-target="menu-panel"><i class="bi bi-calendar2-check"></i><span><b>Stok makanan hari ini</b><small>{{ today()->translatedFormat('d F Y') }}</small></span></button>
</div>

<div class="grid two-col inventory-layout">
    <div>
        <section class="card card-pad inventory-panel active" id="ingredients-panel">
            <div class="card-title"><div><h2>Gudang bahan baku</h2><p>Bertambah saat pembelian diterima dan tidak direset harian.</p></div><span class="badge gray">{{ $ingredientStocks->total() }} item</span></div>
            <div class="inventory-note"><i class="bi bi-info-circle"></i><span>Gunakan stok ini untuk beras, ayam, telur, minyak, bumbu, dan bahan produksi lainnya.</span></div>
            <div class="table-wrap"><table><thead><tr><th>Bahan baku</th><th>Kategori</th><th>Stok gudang</th><th>Status</th></tr></thead><tbody>
                @forelse($ingredientStocks as $stock)
                    <tr><td><div class="cell-main">{{ $stock->product->name }}</div><div class="cell-sub">{{ $stock->product->sku }}</div></td><td>{{ $stock->product->category?->name ?? 'Bahan baku' }}</td><td class="money">{{ $stock->quantity }} {{ $stock->product->unit }}</td><td><span class="badge {{ $stock->quantity <= 0 ? 'red' : ($stock->quantity <= $stock->product->minimum_stock ? 'amber' : '') }}">{{ $stock->quantity <= 0 ? 'Habis' : ($stock->quantity <= $stock->product->minimum_stock ? 'Menipis' : 'Aman') }}</span></td></tr>
                @empty
                    <tr><td colspan="4"><div class="cart-empty">Belum ada bahan baku. Tambahkan produk dengan jenis “Bahan baku”.</div></td></tr>
                @endforelse
            </tbody></table></div>
            <div class="pagination">{{ $ingredientStocks->appends(['menu' => $menuStocks->currentPage()])->links() }}</div>
        </section>

        <section class="card card-pad inventory-panel" id="menu-panel">
            <div class="card-title"><div><h2>Stok makanan hari ini</h2><p>Jumlah porsi siap dijual khusus {{ today()->translatedFormat('l, d F Y') }}.</p></div><span class="badge">{{ $menuStocks->total() }} menu</span></div>
            <div class="inventory-note menu"><i class="bi bi-sun"></i><span>Stok menu baru dimulai dari 0 pada hari berikutnya. Isi sesuai jumlah makanan yang siap dijual hari ini.</span></div>
            <div class="table-wrap"><table><thead><tr><th>Menu</th><th>Kategori</th><th>Siap dijual</th><th>Status</th></tr></thead><tbody>
                @forelse($menuStocks as $stock)
                    <tr><td><div class="cell-main">{{ $stock->product->name }}</div><div class="cell-sub">{{ $stock->product->sku }}</div></td><td>{{ $stock->product->category?->name ?? 'Menu' }}</td><td class="money">{{ $stock->quantity }} {{ $stock->product->unit }}</td><td><span class="badge {{ $stock->quantity <= 0 ? 'red' : ($stock->quantity <= $stock->product->minimum_stock ? 'amber' : '') }}">{{ $stock->quantity <= 0 ? 'Habis' : ($stock->quantity <= $stock->product->minimum_stock ? 'Menipis' : 'Siap jual') }}</span></td></tr>
                @empty
                    <tr><td colspan="4"><div class="cart-empty">Belum ada menu aktif untuk hari ini.</div></td></tr>
                @endforelse
            </tbody></table></div>
            <div class="pagination">{{ $menuStocks->appends(['bahan' => $ingredientStocks->currentPage()])->links() }}</div>
        </section>
    </div>

    <section class="card card-pad"><div class="card-title"><div><h2>Pergerakan terbaru</h2><p>Jejak perubahan kedua jenis stok</p></div></div><div class="list">
        @forelse($movements as $move)<div class="list-item"><span class="list-icon">{{ $move->quantity > 0 ? '+' : '−' }}</span><div class="list-body"><div class="list-title">{{ $move->product_name }}</div><div class="list-sub">{{ $move->notes }} · {{ \Carbon\Carbon::parse($move->created_at)->diffForHumans() }}</div></div><span class="badge {{ $move->quantity < 0 ? 'amber' : '' }}">{{ $move->quantity > 0 ? '+' : '' }}{{ $move->quantity }}</span></div>@empty<div class="cart-empty">Belum ada pergerakan.</div>@endforelse
    </div></section>
</div>
@endsection

@push('modals')
<div class="modal" id="adjust-modal"><div class="modal-card"><div class="modal-head"><div><h2>Penyesuaian stok</h2><div class="hint">Sistem otomatis menyesuaikan gudang atau stok menu hari ini.</div></div><button class="modal-close" onclick="closeModal('adjust-modal')">×</button></div><form method="POST" action="{{ route('inventory.adjust') }}" class="form-grid">@csrf
    <div class="field full"><label>Produk</label><select name="product_id" required>
        <optgroup label="Gudang bahan baku">@foreach($inventoryProducts->where('product_type', 'ingredient') as $product)<option value="{{ $product->id }}">[Bahan baku] {{ $product->name }}</option>@endforeach</optgroup>
        <optgroup label="Stok makanan hari ini">@foreach($inventoryProducts->where('product_type', 'menu') as $product)<option value="{{ $product->id }}">[Menu hari ini] {{ $product->name }}</option>@endforeach</optgroup>
    </select></div>
    <div class="field"><label>Jenis</label><select name="type"><option value="adjustment_in">Tambah stok</option><option value="adjustment_out">Kurangi stok</option></select></div>
    <div class="field"><label>Jumlah</label><input type="number" name="quantity" min="1" required></div>
    <div class="field full"><label>Alasan</label><textarea name="notes" required placeholder="Contoh: Produksi 20 porsi / hasil stock opname"></textarea></div>
    <div class="field full"><button class="btn btn-primary">Simpan penyesuaian</button></div>
</form></div></div>
@endpush

@push('scripts')
<script>
document.querySelectorAll('.inventory-tab').forEach(tab => tab.addEventListener('click', () => {
    document.querySelectorAll('.inventory-tab').forEach(item => item.classList.toggle('active', item === tab));
    document.querySelectorAll('.inventory-panel').forEach(panel => panel.classList.toggle('active', panel.id === tab.dataset.target));
}));
if(new URLSearchParams(location.search).has('menu')) document.querySelector('[data-target="menu-panel"]').click();
</script>
@endpush
