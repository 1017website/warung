@extends('layouts.app')
@section('title', 'Stok / Gudang')
@section('content')
@php($qtyFmt=fn($value)=>rtrim(rtrim(number_format((float)$value,3,',','.'),'0'),','))
<div class="page-head">
    <div>
        <h1><i class="bi bi-box-seam"></i> Stok bahan baku & olahan</h1>
        <p>Kolom input dapat diedit langsung; kolom perhitungan diperbarui otomatis dari pergerakan stok.</p>
    </div>
    <div class="actions">
        <button class="btn btn-soft" onclick="openModal('count-modal')"><i class="bi bi-clipboard-check"></i> Stock opname</button>
        <button class="btn btn-soft" onclick="openModal('adjust-modal')"><i class="bi bi-sliders2"></i> Penyesuaian</button>
        <button class="btn btn-soft" onclick="openModal('reprocess-modal')"><i class="bi bi-recycle"></i> Proses ulang</button>
        <button class="btn btn-soft" onclick="openModal('waste-modal')"><i class="bi bi-trash3"></i> Catat rusak</button>
        <button class="btn btn-primary" onclick="openModal('production-modal')"><i class="bi bi-arrow-repeat"></i> Catat produksi</button>
    </div>
</div>

<section class="card card-pad" style="margin-bottom:16px">
    <div class="card-title"><div><h2>Stok bahan baku</h2><p>Stok terolah dihitung dari produksi; stok terpakai dan keterangan dapat di-live edit.</p></div><span class="badge gray">{{ $ingredientStocks->count() }} bahan</span></div>
    <div class="inventory-note"><i class="bi bi-info-circle"></i><span>Stok awal otomatis mengambil sisa hari sebelumnya. Koreksi stok awal hanya tersedia bagi Admin Operasional dan jenjang di atasnya. Kolom <b>Rusak / tidak layak</b> diisi manual untuk bahan yang tidak bisa diproses ulang dan langsung mengurangi sisa stok. Stock opname mereset saldo sistem ke sisa fisik.</span></div>
    <div class="table-wrap"><table class="inventory-live-table"><thead><tr><th>SKU / bahan baku</th><th>Kategori</th><th>Stok awal</th><th>Stok datang</th><th>Stok terpakai</th><th>Stok terolah</th><th>Rusak / tidak layak</th><th>Sisa stok</th><th>Keterangan</th><th>Status</th></tr></thead><tbody>
    @forelse($ingredientStocks as $stock)
        @php($min=$stock->product->minimum_stock)
        @php($status=$stock->quantity<=0?'KOSONG':($stock->quantity<=max(1,$min/2)?'Tidak aman':($stock->quantity<=$min?'Perlu perhatian':'Aman')))
        @php($class=$status==='Aman'?'':($status==='Perlu perhatian'?'amber':'red'))
        <tr>
            <td><div class="cell-main">{{ $stock->product->name }}</div><div class="cell-sub">{{ $stock->product->sku }} · {{ $stock->product->unit }}</div></td>
            <td>{{ $stock->product->category?->name ?? 'Bahan baku' }}</td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="opening_quantity" value="{{ (float)$stock->opening }}" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}" @disabled(!$canEditOpening) title="{{ $canEditOpening?'Edit stok awal':'Khusus Admin Operasional' }}"></td>
            <td>{{ $qtyFmt($stock->incoming) }}</td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="used_quantity" value="{{ (float)$stock->used }}" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}"></td>
            <td>{{ $qtyFmt($stock->processed) }}</td>
            <td><input class="inventory-live-input waste-input" type="number" min="0" step="0.001" name="waste_quantity" value="{{ (float)$stock->waste }}" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}" title="Total rusak / tidak layak hari ini"></td>
            <td class="money"><span data-stock-balance="{{ $stock->product_id }}">{{ $qtyFmt($stock->quantity) }}</span> {{ $stock->product->unit }}</td>
            <td><input class="inventory-live-notes" name="notes" value="{{ $stock->inventory_notes }}" maxlength="500" placeholder="Tambah keterangan" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}"></td>
            <td><span class="badge {{ $class }}">{{ $status }}</span><div class="cell-sub">Batas min. {{ $qtyFmt($min) }} {{ $stock->product->unit }}</div></td>
        </tr>
    @empty
        <tr><td colspan="10"><div class="cart-empty">Belum ada bahan baku.</div></td></tr>
    @endforelse
    </tbody></table></div>
</section>

<section class="card card-pad">
    <div class="card-title"><div><h2>Stok olahan hari ini</h2><p>Rumus: stok awal + tambahan/hasil proses ulang − terjual − konsumsi − diproses ulang − rusak / tidak layak.</p></div><span class="badge">{{ today()->translatedFormat('d F Y') }}</span></div>
    <div class="table-wrap"><table class="inventory-live-table"><thead><tr><th>SKU / olahan</th><th>Kategori</th><th>Stok awal</th><th>Tambahan olahan</th><th>Terjual</th><th>Konsumsi</th><th>Diproses ulang</th><th>Rusak / tidak layak</th><th>Sisa sistem</th><th>Sisa fisik</th><th>Selisih SO</th><th>Keterangan</th><th>Status</th></tr></thead><tbody>
    @forelse($menuStocks as $stock)
        @php($actual=$stock->count?->actual_quantity)
        @php($difference=$actual===null?null:(float)$stock->count->expected_quantity-(float)$actual)
        @php($balanced=$difference!==null&&abs((float)$difference)<0.001)
        <tr>
            <td><div class="cell-main">{{ $stock->product->name }}</div><div class="cell-sub">{{ $stock->product->sku }} · {{ $stock->product->unit }}</div></td>
            <td>{{ $stock->product->category?->name ?? 'Menu' }}</td>
            <td><input class="inventory-live-input" type="number" min="0" step="0.001" name="opening_quantity" value="{{ (float)$stock->opening }}" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}" @disabled(!$canEditOpening) title="{{ $canEditOpening?'Edit stok awal':'Khusus Admin Operasional' }}"></td>
            <td>{{ $qtyFmt($stock->produced) }}</td>
            <td>{{ $qtyFmt($stock->sold) }}</td>
            <td>{{ $qtyFmt($stock->consumption) }}</td>
            <td>{{ $qtyFmt($stock->reprocessed) }}</td>
            <td><input class="inventory-live-input waste-input" type="number" min="0" step="0.001" name="waste_quantity" value="{{ (float)$stock->waste }}" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}" title="Total rusak / tidak layak hari ini"></td>
            <td class="money"><span data-stock-balance="{{ $stock->product_id }}">{{ $qtyFmt($stock->quantity) }}</span> {{ $stock->product->unit }}</td>
            <td class="money">{{ $actual===null?'—':$qtyFmt($actual).' '.$stock->product->unit }}</td>
            <td class="money">{{ $difference===null?'—':$qtyFmt($difference) }}</td>
            <td><input class="inventory-live-notes" name="notes" value="{{ $stock->inventory_notes }}" maxlength="500" placeholder="Tambah keterangan" data-live-stock data-url="{{ route('inventory.row.update',$stock->product) }}"></td>
            <td><span class="badge {{ $difference===null?'gray':($balanced?'':'red') }}">{{ $difference===null?'Belum opname':($balanced?'Balance':'Tidak balance') }}</span>@if($stock->count?->notes)<div class="cell-sub">{{ $stock->count->notes }}</div>@endif</td>
        </tr>
    @empty
        <tr><td colspan="13"><div class="cart-empty">Belum ada menu aktif.</div></td></tr>
    @endforelse
    </tbody></table></div>
</section>

<div class="grid two-col" style="margin-top:16px">
    <section class="card card-pad"><div class="card-title"><div><h2>Produksi terbaru</h2><p>Koneksi bahan baku → olahan</p></div></div><div class="list">@forelse($productions as $production)<div class="list-item"><span class="list-icon"><i class="bi bi-arrow-right"></i></span><div class="list-body"><div class="list-title">{{ $production->ingredient->name }} → {{ $production->menu->name }}</div><div class="list-sub">{{ $qtyFmt($production->ingredient_quantity) }} {{ $production->ingredient->unit }} menghasilkan {{ $qtyFmt($production->output_quantity) }} {{ $production->menu->unit }}</div></div></div>@empty<div class="cart-empty">Belum ada produksi.</div>@endforelse</div></section>
    <section class="card card-pad"><div class="card-title"><div><h2>Pergerakan terbaru</h2><p>Audit perubahan stok</p></div></div><div class="list">@forelse($movements as $move)<div class="list-item"><span class="list-icon">{{ $move->quantity>0?'+':'−' }}</span><div class="list-body"><div class="list-title">{{ $move->product_name }}</div><div class="list-sub">{{ $move->notes }} · {{ \Carbon\Carbon::parse($move->created_at)->diffForHumans() }}</div></div><span class="badge {{ $move->activity==='waste'?'red':($move->quantity<0?'amber':'') }}">{{ $move->quantity>0?'+':'' }}{{ $qtyFmt($move->quantity) }}</span></div>@empty<div class="cart-empty">Belum ada pergerakan.</div>@endforelse</div></section>
</div>
@endsection

@push('modals')
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
filterWasteProducts();
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
