@extends('layouts.app')
@section('title', 'Produk')
@section('content')
@if(session('import_notes'))<div class="alert alert-error"><b>Catatan impor:</b><ul style="margin:6px 0 0 18px">@foreach(session('import_notes') as $note)<li>{{ $note }}</li>@endforeach</ul></div>@endif
<div class="page-head"><div><h1>Daftar produk</h1><p>Harga normal dan online dipilih otomatis berdasarkan jenis pesanan. Harga dapat dibedakan per warung dan satu SKU dapat memiliki beberapa pilihan harga.</p></div><div class="actions"><a class="btn btn-outline" href="{{ route('products.export') }}"><i class="bi bi-file-earmark-arrow-down"></i> Download Excel</a><button class="btn btn-soft" onclick="openModal('category-modal')"><i class="bi bi-tags"></i> Kelola kategori</button><button class="btn btn-soft" onclick="openModal('import-modal')"><i class="bi bi-file-earmark-arrow-up"></i> Import Excel</button><button class="btn btn-primary" onclick="openModal('product-modal')"><i class="bi bi-plus-circle"></i> Tambah produk</button></div></div>
<div class="card card-pad"><div class="card-title"><div><h2>{{ $products->total() }} produk</h2><p>{{ $priceStoreId ? 'Stok dan harga mengikuti '.$activeStore->name : 'Consolidated: harga default ditampilkan; stok mengikuti '.$activeStore->name }}</p></div><form method="GET" class="product-filter"><div class="search"><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama, SKU, barcode…"></div><select name="type" aria-label="Jenis" onchange="this.form.submit()"><option value="">Semua jenis</option><option value="menu" @selected(($filters['type'] ?? '')==='menu')>Menu / olahan</option><option value="ingredient" @selected(($filters['type'] ?? '')==='ingredient')>Bahan baku</option></select><select name="category" aria-label="Kategori" onchange="this.form.submit()"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((int) ($filters['category'] ?? 0)===$category->id)>{{ $category->name }}</option>@endforeach</select></form></div><div class="table-wrap"><table id="product-table"><thead><tr><th>Produk</th><th>Jenis</th><th>Kategori</th><th>Harga beli</th><th>Harga normal</th><th>Harga online</th><th>Stok / batas aman</th><th></th></tr></thead><tbody>
@forelse($products as $product)
@php($currentStock=$product->product_type==='menu'?($product->daily_stock??0):($product->warehouse_stock??0))
@php($isMenu=$product->product_type==='menu')
@php($storeOverrides=$product->storePrices->filter(fn($price)=>$price->selling_price!==null||$price->online_selling_price!==null))
@php($visibleOptions=$product->prices->filter(fn($price)=>$price->store_id===null||$priceStores->contains('id',$price->store_id))->values())
<tr>
    <td><div class="product-cell"><x-menu-icon :icon="$product->displayIcon()" :color="$product->category?->color" /><div><div class="cell-main">{{ $product->name }}</div><div class="cell-sub">{{ $product->sku }} · {{ $product->barcode ?: 'Tanpa barcode' }}</div>@if($product->ingredient_sku && strcasecmp($product->ingredient_sku, $product->sku) !== 0)<div class="cell-sub" title="SKU bahan baku / stok asal dari workbook outlet"><i class="bi bi-link-45deg"></i> SKU bahan {{ $product->ingredient_sku }}</div>@endif</div></div></td>
    <td><span class="badge {{ $isMenu?'':'amber' }}">{{ $isMenu?'Menu harian':'Bahan baku' }}</span></td>
    <td><span class="badge gray">{{ $product->category?->name ?? 'Umum' }}</span></td>
    <td>Rp {{ number_format($product->purchase_price,0,',','.') }}</td>
    <td class="money">
        @if($isMenu)
            Rp {{ number_format($product->priceAt($priceStoreId),0,',','.') }}
            @if($priceStoreId && $product->hasStorePrice($priceStoreId))<div class="cell-sub">Harga khusus {{ $activeStore->name }}</div>@elseif(!$priceStoreId && $storeOverrides->isNotEmpty())<div class="cell-sub">{{ $storeOverrides->count() }} warung beda harga</div>@endif
            @if($priceStoreId && !$product->isAvailableAt($priceStoreId))<div><span class="badge red">Tidak dijual di cabang ini</span></div>@endif
            @if($visibleOptions->isNotEmpty())<div><span class="badge">+{{ $visibleOptions->count() }} pilihan harga</span></div>@endif
        @else — @endif
    </td>
    <td class="money">{{ $isMenu?'Rp '.number_format($product->priceAt($priceStoreId,true),0,',','.'):'—' }}</td>
    <td><span class="badge {{ $currentStock<=$product->minimum_stock?'amber':'' }}">@qty($currentStock) / min. {{ $product->minimum_stock }} {{ $product->unit }}</span></td>
    <td><div class="actions">
        <button class="btn btn-outline btn-sm edit-product" data-id="{{ $product->id }}" data-name="{{ $product->name }}" data-sku="{{ $product->sku }}" data-barcode="{{ $product->barcode }}" data-category="{{ $product->category_id }}" data-unit="{{ $product->unit }}" data-buy="{{ (float) $product->purchase_price }}" data-sell="{{ (float) $product->selling_price }}" data-online="{{ (float) $product->online_selling_price }}" data-minimum="{{ $product->minimum_stock }}" data-icon="{{ $product->icon }}"><i class="bi bi-pencil"></i> Edit</button>
        @if($isMenu)<button class="btn btn-soft btn-sm edit-prices" data-url="{{ route('products.prices',$product) }}" data-prices="{{ json_encode([
            'name' => $product->name,
            'default_price' => (float) $product->selling_price,
            'default_online' => (float) ($product->online_selling_price ?: $product->selling_price),
            'stores' => $product->storePrices->mapWithKeys(fn($price)=>[$price->store_id=>['selling_price'=>$price->selling_price===null?null:(float)$price->selling_price,'online_selling_price'=>$price->online_selling_price===null?null:(float)$price->online_selling_price,'is_available'=>$price->is_available]]),
            'options' => $visibleOptions->map(fn($price)=>['id'=>$price->id,'label'=>$price->label,'price'=>(float)$price->price,'online_price'=>$price->online_price===null?null:(float)$price->online_price,'store_id'=>$price->store_id]),
        ]) }}" title="Harga per warung & pilihan harga"><i class="bi bi-cash-coin"></i> Harga</button>@endif
        <form method="POST" action="{{ route('products.destroy',$product) }}" onsubmit="return confirm('Arsipkan produk ini?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm" title="Arsipkan"><i class="bi bi-archive"></i></button></form>
    </div></td>
</tr>
@empty<tr><td colspan="8"><div class="cart-empty">Belum ada produk.</div></td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $products->links() }}</div></div>
@if($archivedProducts->isNotEmpty() || $archivedCategories->isNotEmpty())
<div class="card card-pad" style="margin-top:16px"><div class="card-title"><div><h2>Arsip yang dapat dipulihkan</h2><p>Riwayat produk dan kategori tidak hilang permanen.</p></div></div><div class="table-wrap"><table><thead><tr><th>Jenis</th><th>Nama</th><th>Diarsipkan</th><th></th></tr></thead><tbody>
@foreach($archivedProducts as $product)<tr><td>Produk</td><td>{{ $product->name }} · {{ $product->sku }}</td><td>{{ $product->deleted_at?->format('d M Y H:i') }}</td><td><form method="POST" action="{{ route('products.restore',$product->id) }}">@csrf<button class="btn btn-soft btn-sm">Pulihkan</button></form></td></tr>@endforeach
@foreach($archivedCategories as $category)<tr><td>Kategori</td><td>{{ $category->name }}</td><td>{{ $category->deleted_at?->format('d M Y H:i') }}</td><td><form method="POST" action="{{ route('products.categories.restore',$category->id) }}">@csrf<button class="btn btn-soft btn-sm">Pulihkan</button></form></td></tr>@endforeach
</tbody></table></div></div>
@endif
@endsection
@push('modals')
<div class="modal" id="category-modal"><div class="modal-card"><div class="modal-head"><div><h2>Kelola kategori</h2><div class="hint">Ikon kategori dipakai semua menu di kategori tersebut kecuali menu yang memiliki ikon sendiri.</div></div><button class="modal-close" onclick="closeModal('category-modal')">×</button></div>
    <form method="POST" action="{{ route('products.categories.store') }}" class="form-grid">@csrf<div class="field"><label>Nama kategori</label><input name="name" required></div><div class="field"><label>Warna</label><input type="color" name="color" value="#78978a" required></div><div class="field full"><label>Ikon</label><input type="hidden" name="icon" data-icon-picker data-placeholder="Tanpa ikon"></div><div class="field full"><button class="btn btn-primary">Tambah kategori</button></div></form>
    <div class="table-wrap" style="margin-top:16px"><table><thead><tr><th>Kategori</th><th>Ubah</th><th></th></tr></thead><tbody>@foreach($categories as $category)<tr><td><div class="product-cell"><x-menu-icon :icon="$category->icon ?: 'bi-tag'" :color="$category->color" size="sm" /><span class="badge" style="border-color:{{ $category->color }}">{{ $category->name }}</span></div></td><td><form method="POST" action="{{ route('products.categories.update',$category) }}" class="actions category-edit-form">@csrf @method('PUT')<input name="name" value="{{ $category->name }}" required style="min-width:130px"><input type="color" name="color" value="{{ $category->color }}" required><input type="hidden" name="icon" value="{{ $category->icon }}" data-icon-picker data-placeholder="Tanpa ikon"><button class="btn btn-outline btn-sm">Simpan</button></form></td><td><form method="POST" action="{{ route('products.categories.destroy',$category) }}">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><i class="bi bi-archive"></i></button></form></td></tr>@endforeach</tbody></table></div>
</div></div>
<div class="modal" id="product-modal"><div class="modal-card"><div class="modal-head"><div><h2>Tambah produk</h2><div class="hint">Stok minimum menentukan status Aman / Perlu perhatian / Tidak aman.</div></div><button class="modal-close" onclick="closeModal('product-modal')">×</button></div><form method="POST" action="{{ route('products.store') }}" class="form-grid">@csrf<div class="field full"><label>Nama produk</label><input name="name" required></div><div class="field full"><label>Jenis</label><select name="product_type" required><option value="menu">Menu siap jual · stok harian</option><option value="ingredient">Bahan baku · stok gudang</option></select></div><div class="field"><label>SKU</label><input name="sku" required></div><div class="field"><label>Barcode</label><input name="barcode"></div><div class="field"><label>Kategori</label><select name="category_id"><option value="">Umum</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div><div class="field"><label>Satuan</label><input name="unit" value="pcs" required></div><div class="field full"><label>Ikon menu</label><input type="hidden" name="icon" data-icon-picker data-placeholder="Ikut ikon kategori"></div><div class="field"><label>Harga beli</label><input type="text" name="purchase_price" data-money-input data-min="0" inputmode="numeric" required></div><div class="field"><label>Harga normal (default)</label><input type="text" name="selling_price" data-money-input data-min="0" inputmode="numeric" required></div><div class="field"><label>Harga online (default)</label><input type="text" name="online_selling_price" data-money-input data-min="0" inputmode="numeric"></div><div class="field"><label>Stok awal</label><input type="number" name="initial_stock" value="0" min="0"></div><div class="field"><label>Stok minimum</label><input type="number" name="minimum_stock" value="5" min="0" required></div><div class="field full"><span class="hint"><i class="bi bi-info-circle"></i> Harga khusus per warung dan pilihan harga tambahan diatur lewat tombol <b>Harga</b> setelah produk tersimpan.</span></div><div class="field full"><button class="btn btn-primary">Simpan produk</button></div></form></div></div>
<div class="modal" id="edit-product-modal"><div class="modal-card"><div class="modal-head"><h2>Edit produk</h2><button class="modal-close" onclick="closeModal('edit-product-modal')">×</button></div><form method="POST" id="edit-product-form" class="form-grid">@csrf @method('PUT')<div class="field full"><label>Nama</label><input id="edit-name" name="name" required></div><div class="field"><label>SKU</label><input id="edit-sku" name="sku" required></div><div class="field"><label>Barcode</label><input id="edit-barcode" name="barcode"></div><div class="field"><label>Kategori</label><select id="edit-category" name="category_id"><option value="">Umum</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div><div class="field"><label>Satuan</label><input id="edit-unit" name="unit" required></div><div class="field full"><label>Ikon menu</label><input type="hidden" id="edit-icon" name="icon" data-icon-picker data-placeholder="Ikut ikon kategori"></div><div class="field"><label>Harga beli</label><input id="edit-buy" type="text" name="purchase_price" data-money-input data-min="0" inputmode="numeric" required></div><div class="field"><label>Harga normal (default)</label><input id="edit-sell" type="text" name="selling_price" data-money-input data-min="0" inputmode="numeric" required></div><div class="field"><label>Harga online (default)</label><input id="edit-online" type="text" name="online_selling_price" data-money-input data-min="0" inputmode="numeric"></div><div class="field"><label>Stok minimum</label><input id="edit-minimum" type="number" name="minimum_stock" min="0" required></div><div class="field full"><button class="btn btn-primary">Simpan perubahan</button></div></form></div></div>
<div class="modal" id="price-modal"><div class="modal-card price-modal-card"><div class="modal-head"><div><h2>Harga <span id="price-product-name"></span></h2><div class="hint">Harga warung yang dikosongkan mengikuti harga default. Pilihan harga tambahan muncul di kasir ketika menu dipilih.</div></div><button class="modal-close" onclick="closeModal('price-modal')">×</button></div>
    <form method="POST" id="price-form">@csrf @method('PUT')
        <h3 class="price-section-title"><i class="bi bi-shop"></i> Harga per warung</h3>
        <div class="table-wrap"><table class="price-table"><thead><tr><th>Warung</th><th>Dijual</th><th>Harga normal</th><th>Harga online</th></tr></thead><tbody>
        @foreach($priceStores as $store)
            <tr><td><div class="cell-main">{{ $store->name }}</div><div class="cell-sub">{{ $store->code }}</div></td>
            <td><input type="hidden" name="stores[{{ $store->id }}][is_available]" value="0"><input type="checkbox" class="price-available" name="stores[{{ $store->id }}][is_available]" value="1" data-store-available="{{ $store->id }}" aria-label="Dijual di {{ $store->name }}"></td>
            <td><input type="text" name="stores[{{ $store->id }}][selling_price]" data-money-input data-store-price="{{ $store->id }}" inputmode="numeric" autocomplete="off"></td>
            <td><input type="text" name="stores[{{ $store->id }}][online_selling_price]" data-money-input data-store-online="{{ $store->id }}" inputmode="numeric" autocomplete="off"></td></tr>
        @endforeach
        </tbody></table></div>
        <h3 class="price-section-title"><i class="bi bi-tags"></i> Pilihan harga (1 SKU, banyak harga)</h3>
        <p class="hint">Contoh: Porsi kecil Rp6.000 dan Porsi besar Rp9.000 untuk SKU yang sama. Stok tetap satu untuk semua pilihan.</p>
        <div id="price-options" class="price-options"></div>
        <button type="button" class="btn btn-soft btn-sm" onclick="addPriceOption()"><i class="bi bi-plus-circle"></i> Tambah pilihan harga</button>
        <div class="actions" style="margin-top:16px"><button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Simpan harga</button></div>
    </form>
    <template id="price-option-template"><div class="price-option-row"><input type="hidden" data-field="id"><div class="field"><label>Nama pilihan</label><input data-field="label" maxlength="60" required placeholder="Porsi besar"></div><div class="field"><label>Harga</label><input data-field="price" data-money-input inputmode="numeric" required></div><div class="field"><label>Harga online</label><input data-field="online_price" data-money-input inputmode="numeric" placeholder="Sama"></div><div class="field"><label>Berlaku di</label><select data-field="store_id"><option value="">Semua warung</option>@foreach($priceStores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></div><button type="button" class="btn btn-danger btn-sm price-option-remove" title="Hapus pilihan"><i class="bi bi-trash3"></i></button></div></template>
</div></div>
<div class="modal" id="import-modal"><div class="modal-card" style="max-width:480px"><div class="modal-head"><div><h2>Import produk dari Excel</h2><div class="hint">Menu, bahan baku, dan stok sekaligus.</div></div><button class="modal-close" onclick="closeModal('import-modal')">×</button></div><form method="POST" action="{{ route('products.import') }}" enctype="multipart/form-data" class="form-grid">@csrf<div class="field full"><div class="inventory-note"><i class="bi bi-info-circle"></i><span>Dua format diterima: (1) hasil <b>Download Excel</b>, termasuk sheet Harga Warung dan Pilihan Harga; (2) workbook outlet seperti <b>POS MENU ALL OUTLET &amp; SKU</b>, <b>Stok Bahan Baku</b>, <b>Stok Olahan Hari Ini</b>, dan <b>Stok CV</b>. Sheet MATANG menjadi menu; MENTAH, SUPPORT, dan CV menjadi bahan baku. Kolom STOK (opsional) menyetel stok <b>{{ $activeStore->name }}</b>.</span></div></div><div class="field full"><label>File .xlsx / .xls / .csv</label><input type="file" name="file" accept=".xlsx,.xls,.csv" required></div><div class="field full"><button class="btn btn-primary">Import & perbarui</button></div></form></div></div>
@endpush
@push('scripts')
<script>
window.MENU_ICONS={bootstrap:@json(\App\Support\MenuIcon::OPTIONS),emoji:@json(\App\Support\MenuIcon::EMOJI)};
function filterRows(q){document.querySelectorAll('#product-table tbody tr').forEach(r=>r.style.display=r.innerText.toLowerCase().includes(q.toLowerCase())?'':'none')}
document.querySelectorAll('.edit-product').forEach(button=>button.addEventListener('click',()=>{
    const d=button.dataset;
    document.getElementById('edit-product-form').action='/produk/'+d.id;
    ['name','sku','barcode','unit','minimum','category'].forEach(k=>document.getElementById('edit-'+k).value=d[k]||'');
    // Nilai dikirim sebagai angka (15000), bukan "15000.00" yang menjadi 1.500.000 saat diformat.
    ['buy','sell','online'].forEach(k=>setMoneyInputValue(document.getElementById('edit-'+k),Number(d[k]||0)));
    setIconPickerValue(document.getElementById('edit-icon'),d.icon);
    openModal('edit-product-modal');
}));
let priceOptionIndex=0;
function addPriceOption(option={}){
    const row=document.getElementById('price-option-template').content.firstElementChild.cloneNode(true),index=priceOptionIndex++;
    row.querySelectorAll('[data-field]').forEach(input=>{input.name=`prices[${index}][${input.dataset.field}]`});
    row.querySelector('[data-field=id]').value=option.id||'';
    row.querySelector('[data-field=label]').value=option.label||'';
    const storeSelect=row.querySelector('[data-field=store_id]');
    storeSelect.value=option.store_id==null?'':String(option.store_id);
    row.querySelector('.price-option-remove').addEventListener('click',()=>row.remove());
    document.getElementById('price-options').appendChild(row);
    initializeMoneyInputs(row);
    setMoneyInputValue(row.querySelector('[data-field=price]'),option.price==null?'':Number(option.price));
    setMoneyInputValue(row.querySelector('[data-field=online_price]'),option.online_price==null?'':Number(option.online_price));
    if(!option.id)row.querySelector('[data-field=label]').focus();
}
document.querySelectorAll('.edit-prices').forEach(button=>button.addEventListener('click',()=>{
    const data=JSON.parse(button.dataset.prices),form=document.getElementById('price-form');
    form.action=button.dataset.url;
    document.getElementById('price-product-name').textContent=data.name;
    form.querySelectorAll('[data-store-price]').forEach(input=>{
        const store=data.stores[input.dataset.storePrice]||{};
        input.placeholder='Default Rp '+money(data.default_price);
        setMoneyInputValue(input,store.selling_price==null?'':Number(store.selling_price));
    });
    form.querySelectorAll('[data-store-online]').forEach(input=>{
        const store=data.stores[input.dataset.storeOnline]||{};
        input.placeholder='Default Rp '+money(data.default_online);
        setMoneyInputValue(input,store.online_selling_price==null?'':Number(store.online_selling_price));
    });
    form.querySelectorAll('[data-store-available]').forEach(input=>{const store=data.stores[input.dataset.storeAvailable];input.checked=store?Boolean(store.is_available):true});
    document.getElementById('price-options').innerHTML='';
    priceOptionIndex=0;
    (data.options||[]).forEach(option=>addPriceOption(option));
    openModal('price-modal');
}));
</script>
@endpush
