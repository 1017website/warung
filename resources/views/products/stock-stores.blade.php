{{-- Warung tujuan stok awal / stok impor. Default cabang aktif; mode consolidated mencentang semua warung. --}}
@if($priceStores->count() > 1)
<div class="field full stock-store-picker"><label>{{ $label }}</label>
    <div class="store-target-list">@foreach($priceStores as $store)<label class="inventory-note"><input type="checkbox" name="stock_store_ids[]" value="{{ $store->id }}" @checked($isConsolidated || $store->id === $activeStore->id) style="width:auto;min-height:auto"><span>{{ $store->name }}</span></label>@endforeach</div>
    <div class="store-target-actions"><button type="button" class="btn btn-outline btn-sm" data-store-check="all">Pilih semua</button><button type="button" class="btn btn-outline btn-sm" data-store-check="none">Kosongkan</button></div>
    <span class="hint">{{ $hint }}</span>
</div>
@endif
