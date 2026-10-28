@extends('layouts.app')
@section('title', 'Stok / Gudang')
@section('content')
@php($qtyFmt=fn($value)=>rtrim(rtrim(number_format((float)$value,3,',','.'),'0'),','))
@php($readOnly=!$stockDate->isToday())
@if(session('import_notes'))<div class="alert alert-error"><b>Catatan impor:</b><ul style="margin:6px 0 0 18px">@foreach(session('import_notes') as $note)<li>{{ $note }}</li>@endforeach</ul></div>@endif
<div class="page-head">
    <div>
        <h1><i class="bi bi-box-seam"></i> Stok bahan baku & olahan</h1>
        <p>Kolom input dapat diedit langsung; kolom perhitungan diperbarui otomatis dari pergerakan stok.</p>
    </div>
    <div class="actions">
        <button class="btn btn-soft" onclick="openModal('stock-import-modal')"><i class="bi bi-file-earmark-arrow-up"></i> Import Excel</button>
        <a class="btn btn-soft" href="{{ route('inventory.export', ['tanggal' => $stockDate->toDateString()]) }}" title="Ekspor stok tanggal {{ $stockDate->translatedFormat('d F Y') }}"><i class="bi bi-file-earmark-arrow-down"></i> Ekspor Excel</a>
        <button class="btn btn-soft" onclick="openModal('count-modal')"><i class="bi bi-clipboard-check"></i> Stock opname</button>
        <button class="btn btn-soft" onclick="openModal('adjust-modal')"><i class="bi bi-sliders2"></i> Penyesuaian</button>
        <button class="btn btn-soft" onclick="openModal('reprocess-modal')"><i class="bi bi-recycle"></i> Proses ulang</button>
        <button class="btn btn-soft" onclick="openModal('waste-modal')"><i class="bi bi-trash3"></i> Catat rusak</button>
        <button class="btn btn-primary" onclick="openModal('production-modal')"><i class="bi bi-arrow-repeat"></i> Catat produksi</button>
    </div>
</div>

<div class="card card-pad inventory-search-bar">
    <div class="search"><input id="inventory-search" type="search" placeholder="Cari bahan baku / olahan: nama, SKU, kategori…" autocomplete="off" aria-label="Cari stok"></div>
    <select id="inventory-status-filter" aria-label="Filter status"><option value="">Semua status</option><option value="alert">Perlu perhatian / tidak aman / kosong</option><option value="unbalanced">Olahan tidak balance</option></select>
    <form method="GET" action="{{ route('inventory') }}" class="actions"><label class="hint" for="inventory-stock-date">Tanggal stok</label><input id="inventory-stock-date" type="date" name="tanggal" value="{{ $stockDate->toDateString() }}" max="{{ today()->toDateString() }}" onchange="this.form.submit()">@if($readOnly)<a class="btn btn-soft" href="{{ route('inventory') }}">Hari ini</a>@endif</form>
    <span class="hint" id="inventory-search-count" aria-live="polite"></span>
</div>
@if($readOnly)<div class="inventory-note" style="margin-bottom:16px"><i class="bi bi-clock-history"></i><span>Menampilkan stok tanggal <b>{{ $stockDate->translatedFormat('d F Y') }}</b> (hanya baca). Pencatatan dan live edit tetap untuk hari ini.</span></div>@endif

<section class="card card-pad" style="margin-bottom:16px">
    <div class="card-title"><div><h2>Stok bahan baku</h2><p>Stok terolah dihitung dari produksi; stok datang, stok terpakai, dan keterangan dapat di-live edit.</p></div><div class="actions"><span class="badge">{{ $stockDate->translatedFormat('d F Y') }}</span><span class="badge gray">{{ $ingredientStocks->count() }} bahan</span></div></div>
    <div class="inventory-note"><i class="bi bi-info-circle"></i><span>Stok awal otomatis mengambil sisa hari sebelumnya. Koreksi stok awal hanya tersedia bagi Admin Operasional dan jenjang di atasnya. Kolom <b>Rusak / tidak layak</b> diisi manual untuk bahan yang tidak bisa diproses ulang dan langsung mengurangi sisa stok. Stock opname mereset saldo sistem ke sisa fisik.</span></div>
    <div class="table-wrap inventory-table-wrap"><table class="inventory-live-table"><thead><tr><th>SKU / bahan baku</th><th>Kategori</th><th>Stok awal</th><th>Stok datang</th><th>Stok terpakai</th><th>Stok terolah</th><th>Rusak / tidak layak</th><th>Sisa stok</th><th>Keterangan</th><th>Status</th></tr></thead><tbody>
    @forelse($ingredientStocks as $stock)
        @php($min=$stock->product->minimum_stock)
        @php($status=$stock->quantity<=0?'KOSONG':($stock->quantity<=max(1,$min/2)?'Tidak aman':($stock->quantity<=$min?'Perlu perhatian':'Aman')))
        @php($class=$status==='Aman'?'':($status==='Perlu perhatian'?'amber':'red'))
        <tr data-stock-row data-search="{{ \Illuminate\Support\Str::lower($stock->product->name.' '.$stock->product->sku.' '.$stock->product->ingredient_sku.' '.($stock->product->category?->name ?? 'Bahan baku')) }}" data-alert="{{ $status==='Aman'?0:1 }}">
            <td><div class="cell-main">{{ $stock->product->name }}</div><div class="cell-sub">{{ $stock->product->sku }} · {{ $stock->product->unit }}</div></td>
            <td>{{ $stock->product->category?->name ?? 'Bahan baku' }}</td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="opening_quantity" value="{{ (float)$stock->opening }}" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}" @disabled($readOnly || !$canEditOpening) title="{{ $canEditOpening?'Edit stok awal':'Khusus Admin Operasional' }}"></td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="incoming_quantity" value="{{ (float)$stock->incoming }}" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}" title="Total stok datang hari ini"></td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="used_quantity" value="{{ (float)$stock->used }}" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}"></td>
            <td>{{ $qtyFmt($stock->processed) }}</td>
            <td><input class="inventory-live-input waste-input" type="number" min="0" step="0.001" name="waste_quantity" value="{{ (float)$stock->waste }}" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}" title="Total rusak / tidak layak hari ini"></td>
            <td class="money"><span data-stock-balance="{{ $stock->product_id }}">{{ $qtyFmt($stock->quantity) }}</span> {{ $stock->product->unit }}</td>
            <td><input class="inventory-live-notes" name="notes" value="{{ $stock->inventory_notes }}" maxlength="500" placeholder="Tambah keterangan" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}"></td>
            <td><span class="badge {{ $class }}">{{ $status }}</span><div class="cell-sub">Batas min. {{ $qtyFmt($min) }} {{ $stock->product->unit }}</div></td>
        </tr>
    @empty
        <tr><td colspan="10"><div class="cart-empty">Belum ada bahan baku.</div></td></tr>
    @endforelse
    <tr class="inventory-search-empty" hidden><td colspan="10"><div class="cart-empty">Tidak ada bahan baku yang cocok dengan pencarian.</div></td></tr>
    </tbody></table></div>
</section>

<section class="card card-pad">
    <div class="card-title"><div><h2>Stok olahan {{ $readOnly ? $stockDate->translatedFormat('d F Y') : 'hari ini' }}</h2><p>Rumus: stok awal + tambahan/hasil proses ulang − terjual − konsumsi − diproses ulang − rusak / tidak layak.</p></div><span class="badge">{{ $stockDate->translatedFormat('d F Y') }}</span></div>
    <div class="table-wrap inventory-table-wrap"><table class="inventory-live-table"><thead><tr><th>SKU / olahan</th><th>Kategori</th><th>Stok awal</th><th>Tambahan olahan</th><th>Terjual</th><th>Konsumsi</th><th>Diproses ulang</th><th>Rusak / tidak layak</th><th>Sisa sistem</th><th>Sisa fisik</th><th>Selisih SO</th><th>Keterangan</th><th>Status</th></tr></thead><tbody>
    @forelse($menuStocks as $stock)
        @php($actual=$stock->count?->actual_quantity)
        @php($difference=$actual===null?null:(float)$stock->count->expected_quantity-(float)$actual)
        @php($balanced=$difference!==null&&abs((float)$difference)<0.001)
        <tr data-stock-row data-search="{{ \Illuminate\Support\Str::lower($stock->product->name.' '.$stock->product->sku.' '.$stock->product->ingredient_sku.' '.($stock->product->category?->name ?? 'Menu')) }}" data-alert="{{ (float)$stock->quantity<=(float)$stock->product->minimum_stock?1:0 }}" data-unbalanced="{{ $difference!==null&&!$balanced?1:0 }}">
            <td><div class="cell-main">{{ $stock->product->name }}</div><div class="cell-sub">{{ $stock->product->sku }} · {{ $stock->product->unit }}</div></td>
            <td>{{ $stock->product->category?->name ?? 'Menu' }}</td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="opening_quantity" value="{{ (float)$stock->opening }}" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}" @disabled($readOnly || !$canEditOpening) title="{{ $canEditOpening?'Edit stok awal':'Khusus Admin Operasional' }}"></td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="produced_quantity" value="{{ (float)$stock->produced }}" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}" title="Total tambahan olahan hari ini"></td>
            <td>{{ $qtyFmt($stock->sold) }}</td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="consumption_quantity" value="{{ (float)$stock->consumption }}" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}" title="Total konsumsi owner/karyawan hari ini"></td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="reprocessed_quantity" value="{{ (float)$stock->reprocessed }}" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}" title="Total diproses ulang hari ini"></td>
            <td><input class="inventory-live-input waste-input" type="number" min="0" step="0.001" name="waste_quantity" value="{{ (float)$stock->waste }}" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}" title="Total rusak / tidak layak hari ini"></td>
            <td class="money"><span data-stock-balance="{{ $stock->product_id }}">{{ $qtyFmt($stock->quantity) }}</span> {{ $stock->product->unit }}</td>
            <td class="money">{{ $actual===null?'—':$qtyFmt($actual).' '.$stock->product->unit }}</td>
            <td class="money">{{ $difference===null?'—':$qtyFmt($difference) }}</td>
            <td><input class="inventory-live-notes" name="notes" value="{{ $stock->inventory_notes }}" maxlength="500" placeholder="Tambah keterangan" data-live-stock @disabled($readOnly) data-url="{{ route('inventory.row.update',$stock->product) }}"></td>
            <td><span class="badge {{ $difference===null?'gray':($balanced?'':'red') }}">{{ $difference===null?'Belum opname':($balanced?'Balance':'Tidak balance') }}</span>@if($stock->count?->notes)<div class="cell-sub">{{ $stock->count->notes }}</div>@endif</td>
        </tr>
    @empty
        <tr><td colspan="13"><div class="cart-empty">Belum ada menu aktif.</div></td></tr>
    @endforelse
    <tr class="inventory-search-empty" hidden><td colspan="13"><div class="cart-empty">Tidak ada olahan yang cocok dengan pencarian.</div></td></tr>
    </tbody></table></div>
</section>

<section class="card card-pad" id="riwayat-edit" style="margin-top:16px">
    <div class="card-title"><div><h2>Riwayat edit stok</h2><p>Setiap perubahan kolom yang diedit langsung di tabel: siapa, kapan, dan nilai sebelum → sesudah.</p></div>
        <form method="GET" action="{{ route('inventory') }}#riwayat-edit" class="actions">@if($readOnly)<input type="hidden" name="tanggal" value="{{ $stockDate->toDateString() }}">@endif<input type="date" name="riwayat_tanggal" value="{{ $historyDate->toDateString() }}" max="{{ today()->toDateString() }}" onchange="this.form.submit()" aria-label="Tanggal riwayat"><span class="badge gray">{{ $editLogs->count() }} perubahan</span></form>
    </div>
    <div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Pengguna</th><th>Produk</th><th>Kolom</th><th>Sebelum</th><th>Sesudah</th></tr></thead><tbody>
    @forelse($editLogs as $log)
        @php($isNotes=$log->field==='notes')
        <tr>
            <td>{{ $log->created_at->format('H:i') }}<div class="cell-sub">{{ $log->created_at->translatedFormat('d M Y') }}</div></td>
            <td><div class="cell-main">{{ $log->user?->name ?? 'Sistem' }}</div>@if($log->user)<div class="cell-sub">{{ $log->user->roleLabel() }}</div>@endif</td>
            <td><div class="cell-main">{{ $log->product?->name ?? 'Produk' }}</div><div class="cell-sub">{{ $log->product?->sku }}</div></td>
            <td>{{ $log->fieldLabel() }}@if($log->reason)<div class="cell-sub">{{ $log->reason }}</div>@endif</td>
            <td class="{{ $isNotes?'':'money' }}">{{ $isNotes?($log->old_value ?? '—'):$qtyFmt($log->old_value) }}</td>
            <td class="{{ $isNotes?'':'money' }}"><b>{{ $isNotes?($log->new_value ?? '—'):$qtyFmt($log->new_value) }}</b></td>
        </tr>
    @empty
        <tr><td colspan="6"><div class="cart-empty">Belum ada edit stok pada {{ $historyDate->translatedFormat('d F Y') }}.</div></td></tr>
    @endforelse
    </tbody></table></div>
</section>

<div class="grid two-col" style="margin-top:16px">
    <section class="card card-pad"><div class="card-title"><div><h2>Produksi terbaru</h2><p>Koneksi bahan baku → olahan</p></div></div><div class="list">@forelse($productions as $production)<div class="list-item"><span class="list-icon"><i class="bi bi-arrow-right"></i></span><div class="list-body"><div class="list-title">{{ $production->ingredient->name }} → {{ $production->menu->name }}</div><div class="list-sub">{{ $qtyFmt($production->ingredient_quantity) }} {{ $production->ingredient->unit }} menghasilkan {{ $qtyFmt($production->output_quantity) }} {{ $production->menu->unit }}</div></div></div>@empty<div class="cart-empty">Belum ada produksi.</div>@endforelse</div></section>
    <section class="card card-pad"><div class="card-title"><div><h2>Pergerakan terbaru</h2><p>Audit perubahan stok</p></div></div><div class="list">@forelse($movements as $move)<div class="list-item"><span class="list-icon">{{ $move->quantity>0?'+':'−' }}</span><div class="list-body"><div class="list-title">{{ $move->product_name }}</div><div class="list-sub">{{ $move->notes }} · {{ \Carbon\Carbon::parse($move->created_at)->diffForHumans() }}</div></div><span class="badge {{ $move->activity==='waste'?'red':($move->quantity<0?'amber':'') }}">{{ $move->quantity>0?'+':'' }}{{ $qtyFmt($move->quantity) }}</span></div>@empty<div class="cart-empty">Belum ada pergerakan.</div>@endforelse</div></section>
</div>
@endsection

@push('modals')
<div class="modal" id="stock-import-modal"><div class="modal-card import-card">
    <div class="modal-head"><div><h2>Import stok dari Excel</h2><div class="hint">Perbarui stok banyak bahan baku & olahan sekaligus.</div></div><button class="modal-close" type="button" onclick="closeModal('stock-import-modal')" aria-label="Tutup">×</button></div>
    <form method="POST" action="{{ route('inventory.import') }}" enctype="multipart/form-data" class="import-form">@csrf
        <div class="import-step"><span class="import-step-no">1</span><div><b>Download template</b><small>Berisi semua produk aktif dan stok sistem {{ $activeStore->name }} saat ini. Isi kolom <b>Jumlah</b> hanya pada produk yang ingin diubah; baris kosong dilewati.</small></div></div>
        <div class="actions"><a class="btn btn-soft btn-sm" href="{{ route('inventory.import-template') }}"><i class="bi bi-file-earmark-arrow-down"></i> Download template stok</a></div>
        <ul class="import-rules"><li>Kolom wajib: <code>SKU</code> dan <code>Jumlah</code>. Kolom <code>Jenis</code> (Olahan / Bahan baku) membedakan SKU yang dipakai menu sekaligus bahan baku.</li><li>Produk baru ditambahkan lewat menu Produk → Import Excel.</li></ul>
        <div class="import-step"><span class="import-step-no">2</span><div><b>Pilih cara impor</b><small>Semua perubahan tercatat di pergerakan stok.</small></div></div>
        <div class="service-grid stock-import-mode"><label class="service-option active"><input type="radio" name="mode" value="set" checked hidden><i class="bi bi-clipboard-check"></i><span>Setel sisa stok<br><small>Jumlah = stok fisik (stock opname)</small></span></label><label class="service-option"><input type="radio" name="mode" value="add" hidden><i class="bi bi-plus-circle"></i><span>Tambah stok<br><small>Jumlah ditambahkan ke stok sekarang</small></span></label></div>
        @include('products.stock-stores', ['label' => 'Stok diperbarui di warung', 'hint' => 'Jumlah yang sama diterapkan ke setiap warung yang dicentang.', 'isConsolidated' => false])
        <div class="import-step"><span class="import-step-no">3</span><div><b>Upload file</b><small>Excel .xlsx / .xls atau .csv, maksimal 5 MB.</small></div></div>
        <input type="file" name="file" accept=".xlsx,.xls,.csv" required data-file-hint="Excel .xlsx / .xls atau .csv" data-max-mb="5">
        <button class="btn btn-primary import-submit"><i class="bi bi-upload"></i> Import stok</button>
    </form>
</div></div>
<div class="modal" id="production-modal"><div class="modal-card"><div class="modal-head"><div><h2>Catat produksi olahan</h2><div class="hint">Bahan baku berkurang dan stok menu bertambah dalam satu proses.</div></div><button class="modal-close" onclick="closeModal('production-modal')">×</button></div><form method="POST" action="{{ route('inventory.production') }}" class="form-grid">@csrf<div class="field full"><label>Bahan baku</label><select name="ingredient_product_id" required>@foreach($inventoryProducts->where('product_type','ingredient') as $product)<option value="{{ $product->id }}">{{ $product->name }} ({{ $product->unit }})</option>@endforeach</select></div><div class="field"><label>Jumlah terolah</label><input type="number" name="ingredient_quantity" min="0.001" step="0.001" required></div><div class="field"><label>Olahan / menu tujuan</label><select name="menu_product_id" required>@foreach($inventoryProducts->where('product_type','menu') as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div><div class="field"><label>Tambahan olahan</label><input type="number" name="output_quantity" min="0.001" step="0.001" required></div><div class="field"><label>Keterangan</label><input name="notes" placeholder="Opsional"></div><div class="field full"><button class="btn btn-primary">Simpan produksi</button></div></form></div></div>
<div class="modal" id="reprocess-modal"><div class="modal-card"><div class="modal-head"><div><h2>Catat proses ulang</h2><div class="hint">Stok olahan sumber berkurang dan stok olahan tujuan bertambah.</div></div><button class="modal-close" onclick="closeModal('reprocess-modal')">×</button></div><form method="POST" action="{{ route('inventory.reprocess') }}" class="form-grid">@csrf<div class="field full"><label>Olahan sumber</label><select name="source_product_id" required>@foreach($inventoryProducts->where('product_type','menu') as $product)<option value="{{ $product->id }}">{{ $product->name }} ({{ $product->unit }})</option>@endforeach</select></div><div class="field"><label>Qty diproses ulang</label><input type="number" name="source_quantity" min="0.001" step="0.001" required></div><div class="field"><label>Olahan tujuan</label><select name="target_product_id" required>@foreach($inventoryProducts->where('product_type','menu') as $product)<option value="{{ $product->id }}">{{ $product->name }} ({{ $product->unit }})</option>@endforeach</select></div><div class="field"><label>Qty hasil</label><input type="number" name="output_quantity" min="0.001" step="0.001" required></div><div class="field full"><label>Keterangan</label><input name="notes" required placeholder="Contoh: lauk hancur diolah menjadi nasi goreng"></div><div class="field full"><button class="btn btn-primary">Simpan proses ulang</button></div></form></div></div>
<div class="modal" id="waste-modal"><div class="modal-card" style="max-width:520px"><div class="modal-head"><div><h2>Catat bahan rusak / tidak layak</h2><div class="hint">Untuk bahan yang tidak bisa diproses ulang. Jumlah menambah kolom Rusak hari ini dan mengurangi sisa stok.</div></div><button class="modal-close" onclick="closeModal('waste-modal')">×</button></div><form method="POST" action="{{ route('inventory.waste') }}" class="form-grid">@csrf
    <div class="field full"><label>Jenis stok</label><div class="service-grid waste-type-grid"><label class="service-option active"><input type="radio" name="stock_type" value="raw" checked hidden><i class="bi bi-basket"></i><span>Mentah · bahan baku</span></label><label class="service-option"><input type="radio" name="stock_type" value="cooked" hidden><i class="bi bi-egg-fried"></i><span>Matang · olahan</span></label></div></div>
    <div class="field full"><label>Produk</label><select name="product_id" id="waste-product" required>@foreach($inventoryProducts as $product)<option value="{{ $product->id }}" data-stock-type="{{ $product->product_type==='menu'?'cooked':'raw' }}">{{ $product->name }} ({{ $product->unit }})</option>@endforeach</select></div>
    <div class="field"><label>Jumlah rusak</label><input type="number" name="quantity" min="0.001" step="0.001" required></div>
    <div class="field"><label>Alasan</label><input name="reason" required maxlength="255" placeholder="Contoh: basi, jatuh, gosong"></div>
    <div class="field full"><button class="btn btn-danger"><i class="bi bi-trash3"></i> Simpan & kurangi stok</button></div></form></div></div>
<div class="modal" id="adjust-modal"><div class="modal-card"><div class="modal-head"><h2>Penyesuaian stok</h2><button class="modal-close" onclick="closeModal('adjust-modal')">×</button></div><form method="POST" action="{{ route('inventory.adjust') }}" class="form-grid">@csrf<div class="field full"><label>Produk</label><select name="product_id" required>@foreach($inventoryProducts as $product)<option value="{{ $product->id }}">[{{ $product->product_type==='menu'?'Olahan':'Bahan baku' }}] {{ $product->name }}</option>@endforeach</select></div><div class="field"><label>Jenis</label><select name="type"><option value="adjustment_in">Tambah stok</option><option value="adjustment_out">Kurangi stok</option><option value="consumption">Konsumsi owner/karyawan</option></select></div><div class="field"><label>Qty</label><input type="number" name="quantity" min="0.001" step="0.001" required></div><div class="field full"><label>Alasan / keterangan</label><textarea name="notes" required></textarea></div><div class="field full"><button class="btn btn-primary">Simpan penyesuaian</button></div></form></div></div>
<div class="modal" id="count-modal"><div class="modal-card"><div class="modal-head"><div><h2>Stock opname akhir hari</h2><div class="hint">Saldo sistem akan disesuaikan ke sisa fisik dan menjadi dasar stok awal berikutnya.</div></div><button class="modal-close" onclick="closeModal('count-modal')">×</button></div><form method="POST" action="{{ route('inventory.count') }}" class="form-grid">@csrf<div class="field full"><label>Produk</label><select name="product_id" required>@foreach($inventoryProducts as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div><div class="field"><label>Sisa fisik</label><input type="number" name="actual_quantity" min="0" step="0.001" required></div><div class="field"><label>Keterangan</label><input name="notes" placeholder="Contoh: selisih jatuh/rusak"></div><div class="field full"><button class="btn btn-primary">Simpan & reset saldo</button></div></form></div></div>
@endpush

@push('scripts')
<script>
function filterWasteProducts(){
    const type=document.querySelector('#waste-modal [name=stock_type]:checked')?.value,select=document.getElementById('waste-product');
    document.querySelectorAll('#waste-modal .service-option').forEach(option=>option.classList.toggle('active',option.querySelector('input').checked));
    [...select.options].forEach(option=>{option.hidden=option.dataset.stockType!==type;option.disabled=option.hidden});
    if(select.selectedOptions[0]?.hidden)select.value=[...select.options].find(option=>!option.hidden)?.value||'';
}
document.querySelectorAll('#waste-modal [name=stock_type]').forEach(input=>input.addEventListener('change',filterWasteProducts));
document.querySelectorAll('#stock-import-modal [name=mode]').forEach(input=>input.addEventListener('change',()=>document.querySelectorAll('#stock-import-modal .service-option').forEach(option=>option.classList.toggle('active',option.querySelector('input').checked))));
filterWasteProducts();
const inventorySearch=document.getElementById('inventory-search'),inventoryStatus=document.getElementById('inventory-status-filter');
function filterInventory(){
    const terms=inventorySearch.value.trim().toLowerCase().split(/\s+/).filter(Boolean),status=inventoryStatus.value;let shown=0,total=0;
    document.querySelectorAll('.inventory-live-table tbody').forEach(body=>{
        const rows=[...body.querySelectorAll('tr[data-stock-row]')];let visible=0;
        rows.forEach(row=>{
            const match=terms.every(term=>row.dataset.search.includes(term))&&(status!=='alert'||row.dataset.alert==='1')&&(status!=='unbalanced'||row.dataset.unbalanced==='1');
            row.hidden=!match;if(match)visible++;
        });
        shown+=visible;total+=rows.length;
        const empty=body.querySelector('.inventory-search-empty');if(empty)empty.hidden=!rows.length||visible>0;
    });
    document.getElementById('inventory-search-count').textContent=terms.length||status?shown+' dari '+total+' produk':'';
    try{sessionStorage.setItem('inventory-filter',JSON.stringify({q:inventorySearch.value,status}))}catch(error){}
}
try{const saved=JSON.parse(sessionStorage.getItem('inventory-filter')||'{}');inventorySearch.value=saved.q||'';inventoryStatus.value=saved.status||''}catch(error){}
inventorySearch.addEventListener('input',filterInventory);inventoryStatus.addEventListener('change',filterInventory);
inventorySearch.addEventListener('keydown',event=>{if(event.key==='Escape'){inventorySearch.value='';filterInventory()}});
filterInventory();
document.querySelectorAll('[data-live-stock]').forEach(input=>{
    let savedValue=input.value;
    input.addEventListener('change',async()=>{
        input.classList.add('is-saving');
        try{
            const response=await fetch(input.dataset.url,{
                method:'PATCH',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
                body:JSON.stringify({[input.name]:input.value})
            });
            const payload=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(payload.message||Object.values(payload.errors||{})[0]?.[0]||'Perubahan gagal disimpan.');
            savedValue=input.value;
            input.classList.remove('is-saving');
            input.classList.add('is-saved');
            setTimeout(()=>location.reload(),350);
        }catch(error){
            input.value=savedValue;
            input.classList.remove('is-saving');
            alert(error.message);
        }
    });
});
</script>
@endpush
