<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rekap Tutup Kasir · {{ $store->name }} · {{ today()->format('d-m-Y') }}</title>
@php
    $rp = fn ($value) => ((float) $value < 0 ? '-Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    $difference = $closing ? (float) $closing->difference : null;
@endphp
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef1ef;color:#000}
.sheet{background:#fff;margin:20px auto;overflow-wrap:anywhere}
.sheet h1,.sheet h2,.sheet p{margin:0}
.kv{display:flex;justify-content:space-between;gap:8px}.kv>span{min-width:0}.kv>span:last-child{text-align:right;white-space:nowrap}
.kv.strong{font-weight:bold}
table{width:100%;border-collapse:collapse}th,td{text-align:left;vertical-align:top}th:last-child,td:last-child{text-align:right}
.status{font-weight:bold;text-align:center}
.sign{display:grid;grid-template-columns:1fr 1fr;gap:12px;text-align:center;break-inside:avoid}.sign div{display:flex;flex-direction:column;justify-content:space-between}.sign .line{border-top:1px solid #000;padding-top:3px}
.section{break-inside:avoid}

/* A4: arsip / laporan ke manajemen */
.paper-a4 .sheet{width:210mm;max-width:calc(100% - 24px);min-height:297mm;padding:14mm 14mm;font:12px/1.5 Arial,Helvetica,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.08)}
.paper-a4 .head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:2px solid #000;padding-bottom:10px;margin-bottom:14px}
.paper-a4 .head h1{font-size:20px}.paper-a4 .head .brand{font-size:15px;font-weight:bold}.paper-a4 .head .meta{text-align:right;font-size:11px}
.paper-a4 .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}.paper-a4 .grid>:only-child{grid-column:1/-1}
.paper-a4 .section{border:1px solid #bbb;border-radius:4px;padding:10px 12px}
.paper-a4 h2{font-size:13px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
.paper-a4 .kv{padding:3px 0;border-bottom:1px dotted #ccc}.paper-a4 .kv:last-child{border-bottom:0}
.paper-a4 .kv.strong{font-size:14px;border-top:1px solid #000;border-bottom:0;margin-top:2px;padding-top:5px}
.paper-a4 th,.paper-a4 td{padding:5px 6px;border-bottom:1px solid #ddd}.paper-a4 th{background:#f2f2f2;font-size:11px;text-transform:uppercase}
.paper-a4 .status{border:2px solid #000;padding:6px;margin-bottom:14px}
.paper-a4 .notes,.paper-a4 .detail,.paper-a4 .turnover{margin-bottom:14px}
.paper-a4 .turnover .figures{display:flex;flex-wrap:wrap}
.paper-a4 .turnover .kv{flex:1 1 0;display:block;border:0;border-left:1px solid #ddd;margin:0;padding:2px 10px}.paper-a4 .turnover .kv:first-child{border-left:0;padding-left:0}
.paper-a4 .turnover .kv>span{display:block;text-align:left}.paper-a4 .turnover .kv>span:first-child{font-size:10px;text-transform:uppercase;color:#444}
.paper-a4 .turnover .kv>span:last-child{font-size:14px;font-weight:bold}.paper-a4 .turnover .kv.strong>span:last-child{font-size:18px}
.paper-a4 .trx-table{font-size:11px}.paper-a4 .trx-table tr{break-inside:avoid}.paper-a4 .trx-table td:first-child{color:#555;width:24px}.paper-a4 .trx-table td:last-child,.paper-a4 .trx-table .nowrap{white-space:nowrap}
.paper-a4 .trx-table .muted td{color:#555}.paper-a4 .trx-table .muted td:last-child{text-decoration:line-through}
.paper-a4 .trx-table tfoot td{font-weight:bold;font-size:12px;border-top:2px solid #000;border-bottom:0}
.paper-a4 .sign{margin-top:28px;width:70%;margin-left:auto}.paper-a4 .sign div{height:80px}

/* POS 58 mm: printer struk thermal */
.paper-58 .sheet{width:58mm;padding:3mm 4mm;font:11px/1.4 "Courier New",monospace}
.paper-58 .head{text-align:center}.paper-58 .head h1{font-size:13px}.paper-58 .head .brand{font-weight:bold}
.paper-58 .head .meta{text-align:left;margin-top:4px}
.paper-58 .grid{display:block}.paper-58 .net{display:none}
.paper-58 .section,.paper-58 .head,.paper-58 .notes{border-top:1px dashed #000;padding-top:5px;margin-top:6px}
.paper-58 .head{border-top:0;padding-top:0;margin-top:0}
.paper-58 .detail{border-top:1px dashed #000;padding-top:5px;margin-top:6px}
.paper-58 .trx{border-top:1px dotted #000;padding:4px 0;break-inside:avoid}.paper-58 .trx-head,.paper-58 .trx-status{font-weight:bold}
.paper-58 .trx-total{border-top:1px dashed #000;padding-top:4px}
.paper-58 h2{font-size:11px;text-transform:uppercase;margin-bottom:2px}
.paper-58 .kv.strong{font-size:12px}
.paper-58 th{font-size:10px;border-bottom:1px solid #000}.paper-58 td{padding:1px 0}.paper-58 th:last-child,.paper-58 td:last-child{padding-left:4px;white-space:nowrap}
.paper-58 .status{border:1px solid #000;padding:3px;margin-top:6px}
.paper-58 .sign{margin-top:14px;gap:6px;font-size:10px}.paper-58 .sign div{height:56px}

.actions{text-align:center;margin:12px auto;max-width:560px;padding:0 16px;font:14px/1.5 sans-serif}
.paper-switch{display:inline-flex;border:1px solid #476c5c;border-radius:6px;overflow:hidden;margin:6px 0}
.paper-switch a{padding:10px 16px;min-height:44px;color:#476c5c;text-decoration:none}.paper-switch a[aria-current="page"]{background:#476c5c;color:#fff}
.print-controls{display:flex;justify-content:center;align-items:end;gap:12px;flex-wrap:wrap;margin:12px 0}
.print-controls label{display:grid;gap:5px;text-align:left}
.actions select{font:inherit;max-width:100%;min-height:44px;padding:8px;border:1px solid #798a82;border-radius:6px;background:#fff;color:#111}
.print-action{display:inline-block;background:#476c5c;color:#fff;border:0;border-radius:6px;padding:12px 24px;min-height:44px;font:inherit;cursor:pointer;text-decoration:none}
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
        <a href="{{ route('pos.close.print', array_filter(['paper' => 'a4', 'detail' => $detail ? 1 : null])) }}" @if($paper === 'a4') aria-current="page" @endif>A4</a>
        <a href="{{ route('pos.close.print', array_filter(['paper' => '58', 'detail' => $detail ? 1 : null])) }}" @if($paper === '58') aria-current="page" @endif>POS 58 mm</a>
    </nav>
    <nav class="paper-switch" aria-label="Isi rekap">
        <a href="{{ route('pos.close.print', ['paper' => $paper]) }}" @unless($detail) aria-current="page" @endunless>Ringkas</a>
        <a href="{{ route('pos.close.print', ['paper' => $paper, 'detail' => 1]) }}" @if($detail) aria-current="page" @endif>Detail transaksi</a>
    </nav>
    <div class="print-controls">
        @if($paper === '58' && ($rawbtPrinter || $eposPayload))
            <label for="print-method">Cara cetak<select id="print-method">
                @if($rawbtPrinter)<option value="rawbt">RawBT Android · {{ $rawbtPrinter->name }}</option>@endif
                @if($eposPayload)<option value="epos">Epson · {{ $eposPayload['printer']['name'] }}</option>@endif
                <option value="browser">Dialog cetak browser</option>
            </select></label>
        @endif
        <a class="print-action" id="print-action" href="#">Cetak rekap</a>
    </div>
    <p id="print-status" role="status"></p>
    <details><summary>Bantuan cetak</summary>
        @if($paper === 'a4')
            <p>Pada dialog cetak pilih kertas <b>A4</b>, orientasi potret, skala 100%, dan matikan header/footer. Untuk file PDF, pilih tujuan <b>Simpan sebagai PDF</b>.</p>
        @else
            <p>Pada dialog cetak pilih printer struk dengan kertas <b>58 mm</b>, skala 100%, margin tidak ada, dan matikan header/footer.</p>
            @if($rawbtPrinter)<p>Di Android, gunakan RawBT agar rekap langsung terkirim ke printer Bluetooth.</p>@endif
        @endif
    </details>
    <a href="{{ route('pos.close') }}">Kembali ke tutup kasir</a>
</div>

<article class="sheet">
    <header class="head">
        <div>
            <h1>Rekap Tutup Kasir{{ $detail ? ' · Detail' : '' }}</h1>
            <div class="brand">{{ $store->brandName() }}</div>
            <div>{{ $store->name }}</div>
            @if($paper === 'a4' && $store->address)<div>{{ $store->address }}</div>@endif
        </div>
        <div class="meta">
            <div class="kv"><span>Tanggal</span><span>{{ ($closing?->closing_date ?? today())->translatedFormat('d F Y') }}</span></div>
            @if($closing)
                <div class="kv"><span>Ditutup</span><span>{{ $closing->closed_at?->format('H:i') }}</span></div>
                <div class="kv"><span>Kasir</span><span>{{ $closing->user?->name ?? '—' }}</span></div>
                <div class="kv"><span>Otorisasi</span><span>{{ $closing->authorizer?->name ?? '—' }}</span></div>
            @endif
            <div class="kv"><span>Dicetak</span><span>{{ now()->format('d/m/Y H:i') }}</span></div>
        </div>
    </header>

    @unless($closing)
        <div class="status">BELUM DISIMPAN — kas fisik belum direkonsiliasi.</div>
    @endunless

    <section class="section turnover">
        <h2>Omzet harian</h2>
        <div class="figures">
            <div class="kv"><span>Transaksi selesai</span><span>{{ $summary['transactions'] }}</span></div>
            <div class="kv"><span>Penjualan produk</span><span>{{ $rp($summary['grossSales']) }}</span></div>
            @if($summary['discount'] > 0)<div class="kv"><span>Diskon</span><span>{{ $rp(-$summary['discount']) }}</span></div>@endif
            @if($summary['serviceCharge'] > 0)<div class="kv"><span>Service</span><span>{{ $rp($summary['serviceCharge']) }}</span></div>@endif
            @if($summary['tax'] > 0)<div class="kv"><span>Pajak</span><span>{{ $rp($summary['tax']) }}</span></div>@endif
            <div class="kv strong"><span>Omzet</span><span>{{ $rp($summary['turnover']) }}</span></div>
        </div>
    </section>

    <div class="grid">
        <section class="section">
            <h2>Ringkasan kas</h2>
            <div class="kv"><span>Tunai penjualan<span class="net"> (net)</span></span><span>{{ $rp($summary['cashSales']) }}</span></div>
            <div class="kv"><span>Top up tunai</span><span>{{ $rp($summary['cashTopups']) }}</span></div>
            <div class="kv"><span>DP reservasi tunai<span class="net"> (net)</span></span><span>{{ $rp($summary['cashReservationDp']) }}</span></div>
            <div class="kv"><span>Pengeluaran tunai</span><span>{{ $rp(-$summary['cashExpenses']) }}</span></div>
        </section>
        @if($closing)
            <section class="section">
                <h2>Rekonsiliasi</h2>
                <div class="kv"><span>Modal kas awal</span><span>{{ $rp($closing->opening_cash) }}</span></div>
                <div class="kv"><span>Kas seharusnya</span><span>{{ $rp($closing->expected_cash) }}</span></div>
                <div class="kv"><span>Kas fisik</span><span>{{ $rp($closing->actual_cash) }}</span></div>
                <div class="kv strong"><span>Selisih{{ $difference === 0.0 ? '' : ($difference < 0 ? ' (kurang)' : ' (lebih)') }}</span><span>{{ $rp($difference) }}</span></div>
            </section>
        @endif
    </div>

    <div class="grid">
        <section class="section">
            <h2>Rincian pembayaran</h2>
            <table><thead><tr><th>Kanal</th><th>Net diterima</th></tr></thead><tbody>
            @forelse($summary['paymentSummary'] as $payment)
                <tr><td>{{ strtoupper($payment['method']) }}{{ $payment['provider'] ? ' · '.$payment['provider'] : '' }}</td><td>{{ $rp($payment['total']) }}</td></tr>
            @empty
                <tr><td colspan="2">Belum ada pembayaran.</td></tr>
            @endforelse
            </tbody></table>
        </section>
        <section class="section">
            <h2>Produk terjual</h2>
            <table><thead><tr><th>Produk</th><th>Qty</th></tr></thead><tbody>
            @forelse($sales as $item)
                <tr><td>{{ $item->product_name }}</td><td>@qty($item->quantity) {{ $item->unit }}</td></tr>
            @empty
                <tr><td colspan="2">Belum ada produk terjual.</td></tr>
            @endforelse
            </tbody></table>
        </section>
    </div>

    @if($detail)
        @php
            $statusLabel = fn ($trx) => ['voided' => 'Dibatalkan', 'pending' => 'Open bill'][$trx->status] ?? ($trx->transaction_type === 'replacement' ? 'Retur' : 'Selesai');
            $salesTotal = $transactions->where('status', 'completed')->where('transaction_type', 'sale')->sum('total');
        @endphp
        <section class="detail">
            <h2>Detail transaksi ({{ $transactions->count() }})</h2>
            @if($paper === 'a4')
                <table class="trx-table">
                    <thead><tr><th>#</th><th>Invoice</th><th>Kasir · pesanan</th><th>Item</th><th>Pembayaran</th><th>Status</th><th>Total</th></tr></thead>
                    <tbody>
                    @forelse($transactions as $trx)
                        <tr class="{{ $trx->status !== 'completed' ? 'muted' : '' }}">
                            <td>{{ $loop->iteration }}</td>
                            <td class="nowrap"><b>{{ $trx->invoice_no }}</b><br>{{ $trx->transacted_at->format('H:i') }}</td>
                            <td>{{ $trx->user?->name ?? '—' }}<br>{{ \App\Support\EposReceipt::serviceLabel($trx) }}</td>
                            <td>@foreach($trx->items as $item)<div>@qty($item->quantity) × {{ $item->product_name }}</div>@endforeach</td>
                            <td>@foreach($trx->payments as $pay)<div>{{ strtoupper($pay->method) }}{{ $pay->provider ? ' · '.$pay->provider : '' }} <span class="nowrap">{{ $rp($pay->amount) }}</span></div>@endforeach @if($trx->payments->isEmpty() && $trx->payment_method)<div>{{ strtoupper($trx->payment_method) }}</div>@endif @if($trx->change_amount > 0)<div>Kembali <span class="nowrap">{{ $rp($trx->change_amount) }}</span></div>@endif</td>
                            <td>{{ $statusLabel($trx) }}@if($trx->status === 'voided' && $trx->cancel_reason)<br><small>{{ $trx->cancel_reason }}</small>@endif</td>
                            <td>{{ $rp($trx->total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Belum ada transaksi hari ini.</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot><tr><td colspan="6">Total penjualan selesai</td><td>{{ $rp($salesTotal) }}</td></tr></tfoot>
                </table>
            @else
                @forelse($transactions as $trx)
                    <div class="trx">
                        <div class="kv trx-head"><span>{{ $trx->invoice_no }}</span><span>{{ $trx->transacted_at->format('H:i') }}</span></div>
                        <div>{{ \App\Support\EposReceipt::serviceLabel($trx) }} · {{ $trx->user?->name ?? '—' }}</div>
                        @if($trx->status !== 'completed' || $trx->transaction_type === 'replacement')<div class="trx-status">** {{ strtoupper($statusLabel($trx)) }} **</div>@endif
                        @foreach($trx->items as $item)
                            <div class="kv"><span>@qty($item->quantity) × {{ $item->product_name }}</span><span>{{ number_format($item->subtotal, 0, ',', '.') }}</span></div>
                        @endforeach
                        @foreach($trx->payments as $pay)
                            <div class="kv"><span>{{ strtoupper($pay->method) }}{{ $pay->provider ? ' '.$pay->provider : '' }}</span><span>{{ number_format($pay->amount, 0, ',', '.') }}</span></div>
                        @endforeach
                        <div class="kv strong"><span>Total</span><span>{{ $rp($trx->total) }}</span></div>
                    </div>
                @empty
                    <p>Belum ada transaksi hari ini.</p>
                @endforelse
                <div class="kv strong trx-total"><span>TOTAL PENJUALAN</span><span>{{ $rp($salesTotal) }}</span></div>
            @endif
        </section>
    @endif

    @if($closing?->notes)
        <section class="notes"><h2>Catatan</h2><p>{{ $closing->notes }}</p></section>
    @endif

    <div class="sign">
        <div><span>Kasir</span><span class="line">{{ $closing?->user?->name ?? ' ' }}</span></div>
        <div><span>Manager/SPV</span><span class="line">{{ $closing?->authorizer?->name ?? ' ' }}</span></div>
    </div>
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
        printStatus.textContent = 'Rekap terkirim ke printer.';
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
