<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $transaction->invoice_no }}</title>
    <style>
        *{box-sizing:border-box}body{font-family:"Courier New",monospace;margin:0;background:#eef1ef;color:#111}.receipt{width:80mm;min-height:100mm;background:#fff;margin:20px auto;padding:7mm 5mm;font-size:11px;line-height:1.45}.center{text-align:center}.logo{width:46px;height:46px;border:1px solid #222;border-radius:8px;display:grid;place-items:center;margin:0 auto 8px;font-weight:bold;font-size:20px;overflow:hidden}.logo img{width:100%;height:100%;object-fit:cover}h1{font-size:16px;margin:0 0 4px}.muted{color:#555;font-size:9px}.line{border-top:1px dashed #555;margin:12px 0}.row{display:flex;justify-content:space-between;gap:12px;margin:4px 0}.row span:last-child{text-align:right}.item{margin:7px 0}.item-name{font-weight:bold}.total{font-size:13px;font-weight:bold}.actions{text-align:center;margin:12px}.actions button{background:#476c5c;color:white;border:0;border-radius:9px;padding:11px 20px;font-weight:bold;cursor:pointer}@page{size:80mm auto;margin:0}@media print{body{background:white}.receipt{margin:0;width:80mm}.actions{display:none}}
    </style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Cetak struk</button></div>
<article class="receipt">
    <header class="center">
        <div class="logo">@if($tenant->logo_path)<img src="{{ asset('storage/'.$tenant->logo_path) }}">@else W @endif</div>
        <h1>{{ $tenant->name }}</h1>
        <div>{{ $transaction->store->name }}</div><div class="muted">{{ $transaction->store->address }}<br>{{ $transaction->store->phone }}</div>
    </header>
    <div class="line"></div>
    <div class="row"><span>No.</span><span>{{ $transaction->invoice_no }}</span></div>
    <div class="row"><span>Waktu</span><span>{{ $transaction->transacted_at->format('d/m/Y H:i') }}</span></div>
    <div class="row"><span>Kasir</span><span>{{ $transaction->user->name }}</span></div>
    <div class="row"><span>Pesanan</span><span>{{ match($transaction->service_type) {'takeaway' => 'Take Away', 'online' => 'Ojek Online', default => 'Dine In'} }}</span></div>
    @if($transaction->service_type === 'dine_in')<div class="row"><span>No. meja</span><span>{{ $transaction->table_number ?: '—' }}</span></div>@endif
    @if($transaction->service_type === 'online')<div class="row"><span>Platform</span><span>{{ $transaction->online_platform ?: '—' }}</span></div>@endif
    @if($transaction->member)<div class="row"><span>Member</span><span>{{ $transaction->member->member_code }}</span></div>@endif
    <div class="line"></div>
    @foreach($transaction->items as $item)
        <div class="item"><div class="item-name">{{ $item->product_name }}</div><div class="row"><span>{{ $item->quantity }} × {{ number_format($item->price,0,',','.') }}</span><span>{{ number_format($item->subtotal,0,',','.') }}</span></div></div>
    @endforeach
    <div class="line"></div>
    <div class="row"><span>Subtotal</span><span>Rp {{ number_format($transaction->subtotal,0,',','.') }}</span></div>
    @if($transaction->discount > 0)<div class="row"><span>Diskon</span><span>-Rp {{ number_format($transaction->discount,0,',','.') }}</span></div>@endif
    <div class="row total"><span>TOTAL</span><span>Rp {{ number_format($transaction->total,0,',','.') }}</span></div>
    <div class="row"><span>{{ strtoupper($transaction->payment_method) }}</span><span>Rp {{ number_format($transaction->paid_amount,0,',','.') }}</span></div>
    @if($transaction->change_amount > 0)<div class="row"><span>Kembali</span><span>Rp {{ number_format($transaction->change_amount,0,',','.') }}</span></div>@endif
    <div class="line"></div>
    <footer class="center">Terima kasih sudah berbelanja.<br><span class="muted">Simpan struk ini sebagai bukti transaksi.</span></footer>
</article>
</body>
</html>
