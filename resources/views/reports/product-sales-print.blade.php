<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@php
    $rp = fn ($value) => ((float) $value < 0 ? '-Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    $sameDay = $from->isSameDay($to);
    $periodLabel = $sameDay ? $from->translatedFormat('d F Y') : $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y');
    $query = fn (array $override = []) => array_filter($override + ['paper' => $paper, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'type' => $type === 'non_real' ? 'non_real' : null]);
    $presets = [
        'Hari ini' => [today(), today()],
        'Kemarin' => [today()->subDay(), today()->subDay()],
        '7 hari' => [today()->subDays(6), today()],
        'Bulan ini' => [today()->startOfMonth(), today()],
    ];
@endphp
<title>Produk Terjual · {{ $scopeLabel }} · {{ $from->format('d-m-Y') }}{{ $sameDay ? '' : ' sd '.$to->format('d-m-Y') }}</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef1ef;color:#000}
.sheet{background:#fff;margin:20px auto;overflow-wrap:anywhere}
.sheet h1,.sheet h2,.sheet p{margin:0}
.kv{display:flex;justify-content:space-between;gap:8px}.kv>span{min-width:0}.kv>span:last-child{text-align:right;white-space:nowrap}
.kv.strong{font-weight:bold}
table{width:100%;border-collapse:collapse}th,td{text-align:left;vertical-align:top}
.num{text-align:right;white-space:nowrap}
.section{break-inside:avoid}

/* A4: arsip / PDF */
.paper-a4 .sheet{width:210mm;max-width:calc(100% - 24px);min-height:297mm;padding:14mm 14mm;font:12px/1.5 Arial,Helvetica,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.08)}
.paper-a4 .head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:2px solid #000;padding-bottom:10px;margin-bottom:14px}
.paper-a4 .head h1{font-size:20px}.paper-a4 .head .brand{font-size:15px;font-weight:bold}.paper-a4 .head .meta{text-align:right;font-size:11px;flex:0 0 auto}
.paper-a4 h2{font-size:13px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
.paper-a4 .products th,.paper-a4 .products td{padding:5px 6px;border-bottom:1px solid #ddd}
.paper-a4 .products thead th{background:#f2f2f2;font-size:11px;text-transform:uppercase}
.paper-a4 .products tr{break-inside:avoid}.paper-a4 .products td:first-child{color:#555;width:28px}
.paper-a4 .products .category th{background:#fafafa;border-bottom:1px solid #999;padding-top:10px;text-transform:uppercase;font-size:11px;letter-spacing:.04em}
.paper-a4 .products .subtotal td{font-weight:bold;border-bottom:1px solid #999}
.paper-a4 .products tfoot td{font-weight:bold;font-size:13px;border-top:2px solid #000;border-bottom:0}
.paper-a4 .summary{width:50%;margin:16px 0 0 auto;border:1px solid #bbb;border-radius:4px;padding:10px 12px}
.paper-a4 .summary .kv{padding:3px 0;border-bottom:1px dotted #ccc}.paper-a4 .summary .kv:last-child{border-bottom:0}
.paper-a4 .summary .kv.strong{font-size:14px;border-top:1px solid #000;margin-top:2px;padding-top:5px}

@media screen and (max-width:600px){.paper-a4 .sheet{padding:16px}.paper-a4 .head{flex-wrap:wrap}.paper-a4 .head .meta{width:100%;text-align:left}.paper-a4 .summary{width:auto}}

/* POS 58 mm: printer struk thermal */
.paper-58 .sheet{width:58mm;padding:3mm 4mm;font:11px/1.4 "Courier New",monospace}
.paper-58 .head{text-align:center}.paper-58 .head h1{font-size:13px}.paper-58 .head .brand{font-weight:bold}
.paper-58 .head .meta{text-align:left;margin-top:4px}
.paper-58 .group,.paper-58 .summary,.paper-58 .empty{border-top:1px dashed #000;padding-top:5px;margin-top:6px}
.paper-58 h2{font-size:11px;text-transform:uppercase;margin-bottom:2px}
.paper-58 .item{padding:1px 0}.paper-58 .item .kv{padding-left:2mm}
.paper-58 .group>.kv.strong{border-top:1px dotted #000;margin-top:2px;padding-top:2px}
.paper-58 .summary .kv.strong{font-size:12px}

.actions{text-align:center;margin:12px auto;max-width:620px;padding:0 16px;font:14px/1.5 sans-serif}
.paper-switch{display:inline-flex;border:1px solid #476c5c;border-radius:6px;overflow:hidden;margin:6px 0}
.paper-switch a{padding:10px 16px;min-height:44px;color:#476c5c;text-decoration:none}.paper-switch a[aria-current="page"]{background:#476c5c;color:#fff}
.range-form,.print-controls{display:flex;justify-content:center;align-items:end;gap:12px;flex-wrap:wrap;margin:12px 0}
.range-form label,.print-controls label{display:grid;gap:5px;text-align:left}
.actions input,.actions select{font:inherit;max-width:100%;min-height:44px;padding:8px;border:1px solid #798a82;border-radius:6px;background:#fff;color:#111}
.presets{display:flex;justify-content:center;gap:6px;flex-wrap:wrap}
.presets a{padding:6px 12px;border:1px solid #c5cfca;border-radius:999px;color:#2f4a3e;text-decoration:none;font-size:13px}.presets a[aria-current="true"]{background:#e3ece7;border-color:#476c5c}
.btn-soft{background:#fff;color:#476c5c;border:1px solid #476c5c;border-radius:6px;padding:10px 18px;min-height:44px;font:inherit;cursor:pointer}
.print-action{display:inline-block;background:#476c5c;color:#fff;border:0;border-radius:6px;padding:12px 24px;min-height:44px;font:inherit;cursor:pointer;text-decoration:none}
.errors{color:#a12d1b}
.actions details{text-align:left;margin:12px auto;max-width:440px}.actions summary{cursor:pointer;padding:8px 0}
.actions a:focus-visible,.actions button:focus-visible{outline:3px solid #111;outline-offset:3px}

@page{ {!! $paper === 'a4' ? 'size:A4 portrait;margin:10mm' : 'size:auto;margin:0' !!} }
@media print{
 body{background:#fff}.actions{display:none}
 .sheet{margin:0;box-shadow:none}
 .paper-a4 .sheet{width:auto;max-width:none;min-height:0;padding:0}
 .paper-58 .sheet{width:58mm}
}
</style>
</head>
<body class="paper-{{ $paper }}">
<div class="actions">
    <nav class="paper-switch" aria-label="Ukuran kertas">
        <a href="{{ route('reports.product-sales', $query(['paper' => 'a4'])) }}" @if($paper === 'a4') aria-current="page" @endif>A4 / PDF</a>
        <a href="{{ route('reports.product-sales', $query(['paper' => '58'])) }}" @if($paper === '58') aria-current="page" @endif>POS 58 mm</a>
    </nav>
    <form class="range-form" method="GET" action="{{ route('reports.product-sales') }}">
        <input type="hidden" name="paper" value="{{ $paper }}">
        @if($type === 'non_real')<input type="hidden" name="type" value="non_real">@endif
        <label for="range-from">Dari tanggal<input id="range-from" type="date" name="from" value="{{ $from->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
        <label for="range-to">Sampai tanggal<input id="range-to" type="date" name="to" value="{{ $to->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
        <button class="btn-soft">Tampilkan</button>
    </form>
    <nav class="presets" aria-label="Periode cepat">
        @foreach($presets as $label => [$presetFrom, $presetTo])
            <a href="{{ route('reports.product-sales', $query(['from' => $presetFrom->toDateString(), 'to' => $presetTo->toDateString()])) }}" @if($from->isSameDay($presetFrom) && $to->isSameDay($presetTo)) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @if($errors->any())<p class="errors" role="alert">{{ $errors->first() }}</p>@endif
    <div class="print-controls">
        @if($paper === '58' && ($rawbtPrinter || $eposPayload))
            <label for="print-method">Cara cetak<select id="print-method">
                @if($rawbtPrinter)<option value="rawbt">RawBT Android · {{ $rawbtPrinter->name }}</option>@endif
                @if($eposPayload)<option value="epos">Epson · {{ $eposPayload['printer']['name'] }}</option>@endif
                <option value="browser">Dialog cetak browser</option>
            </select></label>
        @endif
        <a class="print-action" id="print-action" href="#">{{ $paper === 'a4' ? 'Cetak / simpan PDF' : 'Cetak laporan' }}</a>
    </div>
    <p id="print-status" role="status"></p>
    <details><summary>Bantuan cetak</summary>
        @if($paper === 'a4')
            <p>Pada dialog cetak pilih kertas <b>A4</b>, orientasi potret, skala 100%, dan matikan header/footer. Untuk file PDF, pilih tujuan <b>Simpan sebagai PDF</b>.</p>
        @else
            <p>Pada dialog cetak pilih printer struk dengan kertas <b>58 mm</b>, skala 100%, margin tidak ada, dan matikan header/footer.</p>
            @if($rawbtPrinter)<p>Di Android, gunakan RawBT agar laporan langsung terkirim ke printer Bluetooth.</p>@endif
        @endif
    </details>
    <a href="{{ $backUrl }}">Kembali</a>
</div>

<article class="sheet">
    <header class="head">
        <div>
            <h1>Laporan Produk Terjual</h1>
            <div class="brand">{{ $store->brandName() }}</div>
            <div>{{ $scopeLabel }}</div>
            @if($paper === 'a4' && $scopeLabel === $store->name && $store->address)<div>{{ $store->address }}</div>@endif
        </div>
        <div class="meta">
            <div class="kv"><span>Periode</span><span>{{ $paper === 'a4' ? $periodLabel : ($sameDay ? $from->format('d/m/Y') : $from->format('d/m/y').'–'.$to->format('d/m/y')) }}</span></div>
            @if($type === 'non_real')<div class="kv"><span>Jenis</span><span>Non-riil</span></div>@endif
            <div class="kv"><span>Dicetak</span><span>{{ now()->format('d/m/Y H:i') }}</span></div>
        </div>
    </header>

    @if($paper === 'a4')
        <table class="products">
            <thead><tr><th>#</th><th>Produk</th><th class="num">Qty</th><th>Satuan</th><th class="num">Penjualan</th></tr></thead>
            <tbody>
            @php($row = 0)
            @forelse($categories as $category => $items)
                <tr class="category"><th colspan="5">{{ $category }}</th></tr>
                @foreach($items as $item)
                    <tr><td>{{ ++$row }}</td><td>{{ $item->product_name }}</td><td class="num">@qty($item->quantity)</td><td>{{ $item->unit }}</td><td class="num">{{ $rp($item->sales) }}</td></tr>
                @endforeach
                <tr class="subtotal"><td></td><td>Subtotal {{ $category }}</td><td class="num">@qty($items->sum('quantity'))</td><td></td><td class="num">{{ $rp($items->sum('sales')) }}</td></tr>
            @empty
                <tr><td colspan="5">Belum ada produk terjual pada periode ini.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr><td></td><td>Total produk terjual</td><td class="num">@qty($totals['quantity'])</td><td></td><td class="num">{{ $rp($totals['grossSales']) }}</td></tr></tfoot>
        </table>
    @else
        @forelse($categories as $category => $items)
            <section class="group">
                <h2>{{ $category }}</h2>
                @foreach($items as $item)
                    <div class="item">
                        <div>{{ $item->product_name }}</div>
                        <div class="kv"><span>@qty($item->quantity) {{ $item->unit }}</span><span>{{ number_format($item->sales, 0, ',', '.') }}</span></div>
                    </div>
                @endforeach
                <div class="kv strong"><span>Subtotal @qty($items->sum('quantity'))</span><span>{{ number_format($items->sum('sales'), 0, ',', '.') }}</span></div>
            </section>
        @empty
            <p class="empty">Belum ada produk terjual pada periode ini.</p>
        @endforelse
    @endif

    <section class="section summary">
        <h2>Ringkasan</h2>
        <div class="kv"><span>Transaksi selesai</span><span>{{ $totals['transactions'] }}</span></div>
        <div class="kv"><span>Total qty</span><span>@qty($totals['quantity'])</span></div>
        <div class="kv"><span>Penjualan produk</span><span>{{ $rp($totals['grossSales']) }}</span></div>
        @if($totals['discount'] > 0)<div class="kv"><span>Diskon</span><span>{{ $rp(-$totals['discount']) }}</span></div>@endif
        @if($totals['serviceCharge'] > 0)<div class="kv"><span>Service</span><span>{{ $rp($totals['serviceCharge']) }}</span></div>@endif
        @if($totals['tax'] > 0)<div class="kv"><span>Pajak</span><span>{{ $rp($totals['tax']) }}</span></div>@endif
        <div class="kv strong"><span>Omzet</span><span>{{ $rp($totals['turnover']) }}</span></div>
    </section>
</article>

@if($paper === '58' && $eposPayload)
<script src="{{ asset('js/epos-printer.js') }}?v={{ is_file(public_path('js/epos-printer.js')) ? filemtime(public_path('js/epos-printer.js')) : 1 }}"></script>
@endif
@if($paper === '58' && $rawbtPrinter)
<script src="{{ asset('js/rawbt-printer.js') }}?v={{ filemtime(public_path('js/rawbt-printer.js')) }}"></script>
@endif
<script>
const rawbtUri = @json($paper === '58' ? $rawbtUri : null);
const eposPayload = @json($paper === '58' ? $eposPayload : null);
const methodSelect = document.getElementById('print-method');
const printAction = document.getElementById('print-action');
const printStatus = document.getElementById('print-status');
const method = () => methodSelect ? methodSelect.value : 'browser';
if (methodSelect) methodSelect.value = rawbtUri && window.RawbtPrinter?.isAndroid() ? 'rawbt' : (eposPayload ? 'epos' : 'browser');
function updatePrintAction() {
    printAction.href = method() === 'rawbt' ? rawbtUri : '#';
    printStatus.textContent = '';
}
methodSelect?.addEventListener('change', updatePrintAction);
updatePrintAction();
async function printEpos() {
    if (printAction.getAttribute('aria-disabled') === 'true') return;
    printAction.setAttribute('aria-disabled', 'true');
    printStatus.textContent = 'Mengirim ke printer…';
    try {
        await window.PosPrinter.printJobs(eposPayload);
        printStatus.textContent = 'Laporan terkirim ke printer.';
    } catch (error) {
        printStatus.textContent = error.message;
    } finally {
        printAction.removeAttribute('aria-disabled');
    }
}
printAction.addEventListener('click', event => {
    if (method() === 'rawbt') return;
    event.preventDefault();
    if (method() === 'epos') printEpos();
    else window.print();
});
window.addEventListener('load', () => {
    const url = new URL(window.location.href);
    if (url.searchParams.get('autoprint') !== '1') return;
    // Hapus penanda agar refresh halaman tidak mencetak ulang.
    url.searchParams.delete('autoprint');
    history.replaceState(null, '', url);
    if (method() === 'browser') window.print();
});
</script>
</body></html>
