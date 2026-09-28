@extends('layouts.app')
@section('title', 'Inventaris')
@section('content')
<div class="page-head">
    <div><h1><i class="bi bi-tools"></i> Inventaris peralatan</h1><p>Barang yang tidak habis dipakai seperti panci, kompor, dan freezer {{ $isConsolidated ? 'di seluruh warung' : 'di '.$activeStore->name }}. Terpisah dari stok bahan baku dan olahan.</p></div>
    <div class="actions">
        <a class="btn btn-outline" href="{{ route('assets.export', $filters) }}"><i class="bi bi-file-earmark-excel"></i> Download Excel</a>
        <button class="btn btn-primary" onclick="openAssetForm()"><i class="bi bi-plus-circle"></i> Tambah barang</button>
    </div>
</div>

<div class="grid stats">
    <div class="card stat"><span class="stat-icon"><i class="bi bi-box2"></i></span><div class="stat-label">Jenis barang</div><div class="stat-value">{{ number_format($summary['items'],0,',','.') }}</div><div class="stat-note">Tercatat aktif</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-check2-circle"></i></span><div class="stat-label">Unit kondisi baik</div><div class="stat-value">{{ number_format($summary['good'],0,',','.') }}</div><div class="stat-note">Siap dipakai</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-exclamation-triangle"></i></span><div class="stat-label">Unit rusak</div><div class="stat-value">{{ number_format($summary['damaged'],0,',','.') }}</div><div class="stat-note">{{ $summary['damaged'] ? 'Perlu diperbaiki / diganti' : 'Tidak ada yang rusak' }}</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-cash-stack"></i></span><div class="stat-label">Nilai inventaris</div><div class="stat-value">Rp {{ number_format($summary['value'],0,',','.') }}</div><div class="stat-note">Jumlah unit × harga beli</div></div>
</div>

<section class="card card-pad">
    <div class="card-title"><div><h2>{{ $assets->total() }} barang</h2><p>Jumlah baik dan rusak dapat diubah kapan saja; setiap perubahan tercatat di riwayat.</p></div></div>
    <form method="GET" class="asset-filter">
        <div class="search"><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama, kode, atau lokasi…"></div>
        <select name="category" aria-label="Kategori"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(($filters['category'] ?? '')===$category)>{{ $category }}</option>@endforeach</select>
        <select name="condition" aria-label="Kondisi"><option value="">Semua kondisi</option><option value="good" @selected(($filters['condition'] ?? '')==='good')>Semua baik</option><option value="damaged" @selected(($filters['condition'] ?? '')==='damaged')>Ada yang rusak</option></select>
        <button class="btn btn-soft"><i class="bi bi-funnel"></i> Terapkan</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Barang</th><th>Kategori</th>@if($isConsolidated)<th>Cabang</th>@endif<th>Baik</th><th>Rusak</th><th>Total</th><th>Harga / nilai</th><th></th></tr></thead><tbody>
    @forelse($assets as $asset)
        <tr>
            <td><div class="cell-main">{{ $asset->name }}</div><div class="cell-sub">{{ $asset->code ?: 'Tanpa kode' }} · {{ $asset->location ?: 'Lokasi belum diisi' }}</div>@if($asset->notes)<div class="cell-sub">{{ $asset->notes }}</div>@endif</td>
            <td><span class="badge gray">{{ $asset->category }}</span></td>
            @if($isConsolidated)<td>{{ $asset->store?->name }}</td>@endif
            <td class="money">{{ number_format($asset->quantity_good,0,',','.') }} {{ $asset->unit }}</td>
            <td><span class="badge {{ $asset->quantity_damaged ? 'red' : 'gray' }}">{{ number_format($asset->quantity_damaged,0,',','.') }} {{ $asset->unit }}</span></td>
            <td class="money">{{ number_format($asset->totalQuantity(),0,',','.') }}</td>
            <td>@if($asset->purchase_price !== null)<div>Rp {{ number_format($asset->purchase_price,0,',','.') }} / {{ $asset->unit }}</div><div class="cell-sub">Nilai Rp {{ number_format($asset->totalValue(),0,',','.') }}</div>@else<span class="cell-sub">—</span>@endif @if($asset->purchase_date)<div class="cell-sub">Dibeli {{ $asset->purchase_date->translatedFormat('d M Y') }}</div>@endif</td>
            <td><div class="actions">
                <button type="button" class="btn btn-outline btn-sm edit-asset" data-url="{{ route('assets.update',$asset) }}" data-asset="{{ json_encode(['name'=>$asset->name,'code'=>$asset->code,'category'=>$asset->category,'unit'=>$asset->unit,'quantity_good'=>$asset->quantity_good,'quantity_damaged'=>$asset->quantity_damaged,'location'=>$asset->location,'purchase_date'=>$asset->purchase_date?->format('Y-m-d'),'purchase_price'=>$asset->purchase_price===null?null:(float)$asset->purchase_price,'notes'=>$asset->notes,'store_id'=>$asset->store_id]) }}"><i class="bi bi-pencil"></i> Ubah</button>
                <form method="POST" action="{{ route('assets.destroy',$asset) }}" onsubmit="return confirm('Arsipkan barang ini?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm" title="Arsipkan"><i class="bi bi-archive"></i></button></form>
            </div></td>
        </tr>
    @empty
        <tr><td colspan="{{ $isConsolidated ? 8 : 7 }}"><div class="cart-empty">Belum ada inventaris. Tambahkan peralatan seperti panci, kompor, atau freezer.</div></td></tr>
    @endforelse
    </tbody></table></div>
    <div class="pagination">{{ $assets->links() }}</div>
</section>

<div class="grid two-col" style="margin-top:16px">
    <section class="card card-pad"><div class="card-title"><div><h2>Riwayat perubahan</h2><p>15 perubahan terakhir</p></div></div><div class="list">
        @forelse($logs as $log)
            <div class="list-item"><span class="list-icon"><i class="bi {{ ['created'=>'bi-plus','updated'=>'bi-pencil','archived'=>'bi-archive','restored'=>'bi-arrow-counterclockwise'][$log->action] ?? 'bi-dot' }}"></i></span><div class="list-body"><div class="list-title">{{ $log->asset?->name ?? 'Barang' }}</div><div class="list-sub">{{ $log->summary }} · {{ $log->user?->name ?? 'Sistem' }} · {{ $log->created_at->diffForHumans() }}</div></div></div>
        @empty<div class="cart-empty">Belum ada perubahan.</div>@endforelse
    </div></section>
    <section class="card card-pad"><div class="card-title"><div><h2>Arsip</h2><p>Barang yang sudah tidak dipakai</p></div></div><div class="list">
        @forelse($archived as $asset)
            <div class="list-item"><span class="list-icon"><i class="bi bi-archive"></i></span><div class="list-body"><div class="list-title">{{ $asset->name }}</div><div class="list-sub">{{ $asset->category }} · diarsipkan {{ $asset->deleted_at->translatedFormat('d M Y') }}</div></div><form method="POST" action="{{ route('assets.restore',$asset->id) }}">@csrf<button class="btn btn-soft btn-sm">Pulihkan</button></form></div>
        @empty<div class="cart-empty">Arsip kosong.</div>@endforelse
    </div></section>
</div>
@endsection

@push('modals')
<div class="modal" id="asset-modal"><div class="modal-card"><div class="modal-head"><div><h2 id="asset-modal-title">Tambah barang inventaris</h2><div class="hint">Contoh: Panci presto 5 unit, 1 rusak.</div></div><button class="modal-close" onclick="closeModal('asset-modal')">×</button></div>
    <form method="POST" id="asset-form" action="{{ route('assets.store') }}" class="form-grid">@csrf<input type="hidden" name="_method" value="POST" id="asset-method">
        <div class="field full"><label>Nama barang</label><input name="name" required maxlength="120" placeholder="Panci presto 10 L"></div>
        <div class="field"><label>Kategori</label><select name="category" required>@foreach($categories as $category)<option value="{{ $category }}">{{ $category }}</option>@endforeach</select></div>
        <div class="field"><label>Kode <small>(opsional)</small></label><input name="code" maxlength="40" placeholder="INV-001"></div>
        <div class="field"><label>Jumlah baik</label><input type="number" name="quantity_good" min="0" step="1" value="1" required></div>
        <div class="field"><label>Jumlah rusak</label><input type="number" name="quantity_damaged" min="0" step="1" value="0" required></div>
        <div class="field"><label>Satuan</label><input name="unit" value="unit" maxlength="20" required></div>
        <div class="field"><label>Lokasi</label><input name="location" maxlength="80" placeholder="Dapur"></div>
        <div class="field"><label>Tanggal beli</label><input type="date" name="purchase_date" max="{{ now()->toDateString() }}"></div>
        <div class="field"><label>Harga beli / unit</label><input type="text" name="purchase_price" data-money-input data-min="0" inputmode="numeric"></div>
        @if(auth()->user()->canAccessAllStores())<div class="field full"><label>Cabang</label><select name="store_id">@foreach($assetStores as $store)<option value="{{ $store->id }}" @selected($store->id===$activeStore->id)>{{ $store->name }}</option>@endforeach</select></div>@endif
        <div class="field full"><label>Catatan</label><textarea name="notes" maxlength="500" rows="2" placeholder="Contoh: tutup retak, perlu servis"></textarea></div>
        <div class="field full"><button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Simpan</button></div>
    </form>
</div></div>
@endpush

@push('scripts')
<script>
function openAssetForm(url=null,data=null){
    const form=document.getElementById('asset-form');
    form.reset();
    form.action=url||@json(route('assets.store'));
    document.getElementById('asset-method').value=url?'PUT':'POST';
    document.getElementById('asset-modal-title').textContent=url?'Ubah barang inventaris':'Tambah barang inventaris';
    if(data)Object.entries(data).forEach(([key,value])=>{const input=form.elements[key];if(input&&key!=='purchase_price')input.value=value??''});
    setMoneyInputValue(form.elements.purchase_price,data?.purchase_price==null?'':Number(data.purchase_price));
    openModal('asset-modal');
}
document.querySelectorAll('.edit-asset').forEach(button=>button.addEventListener('click',()=>openAssetForm(button.dataset.url,JSON.parse(button.dataset.asset))));
</script>
@endpush
