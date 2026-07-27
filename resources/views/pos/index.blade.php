@extends('layouts.app')
@section('title', 'Kasir')
@section('content')
<div class="page-head"><div><h1><i class="bi bi-calculator"></i> Kasir {{ $activeStore->name }}</h1><p>Pilih produk, tentukan pembayaran, lalu cetak struk.</p></div><span class="badge"><span class="dot"></span>Siap melayani</span></div>
<div class="pos-layout">
    <section class="pos-products">
        <div class="search-row">
            <div class="search"><input id="product-search" placeholder="Cari nama, SKU, atau scan barcode…" autocomplete="off"></div>
            <button class="btn btn-soft icon-btn" onclick="document.getElementById('product-search').focus()" title="Fokus pencarian"><i class="bi bi-upc-scan"></i></button>
        </div>
        <div class="category-pills"><button class="pill active" data-category="all">Semua</button>@foreach($categories as $category)<button class="pill" data-category="{{ $category->id }}">{{ $category->name }}</button>@endforeach</div>
        <div class="product-grid" id="product-grid">
            @foreach($products as $product)
                @php($stock = $product->dailyStocks->first()?->quantity ?? 0)
                <button class="product-card" data-id="{{ $product->id }}" data-category="{{ $product->category_id ?: 'none' }}" data-search="{{ strtolower($product->name.' '.$product->sku.' '.$product->barcode) }}" @disabled($stock <= 0)>
                    <span class="product-category">{{ $product->category?->name ?? 'Umum' }}</span>
                    <div class="product-name">{{ $product->name }}</div>
                    <div class="product-meta"><span class="product-price">Rp {{ number_format($product->selling_price,0,',','.') }}</span><span class="product-stock">{{ $stock > 0 ? 'Stok '.$stock : 'Habis' }}</span></div>
                </button>
            @endforeach
        </div>
        @if($products->isEmpty())<div class="card cart-empty">Belum ada produk aktif. Tambahkan produk lebih dulu.</div>@endif
    </section>
    <aside class="card cart">
        <div class="cart-head"><h2>Pesanan baru</h2><span class="cart-count" id="cart-count">0</span></div>
        <div class="member-select">
            <div style="display:flex;gap:7px">
                <select id="member-id"><option value="">Pelanggan umum</option>@foreach($members as $member)<option value="{{ $member->id }}" data-balance="{{ $member->deposit_balance }}">{{ $member->name }} · {{ $member->member_code }}</option>@endforeach</select>
                <button class="btn btn-soft icon-btn" onclick="startScanner()" title="Scan QR member"><i class="bi bi-qr-code-scan"></i></button>
            </div>
            <div id="member-balance" class="hint" style="margin-top:7px;display:none"></div>
        </div>
        <div class="order-service">
            <label>Jenis pesanan</label>
            <div class="service-grid">
                <button type="button" class="service-option active" data-service="dine_in"><i class="bi bi-shop"></i><span>Dine in</span></button>
                <button type="button" class="service-option" data-service="takeaway"><i class="bi bi-bag-check"></i><span>Take away</span></button>
                <button type="button" class="service-option" data-service="online"><i class="bi bi-scooter"></i><span>Ojek online</span></button>
            </div>
            <div class="field service-detail" id="table-field">
                <label for="table-number">Nomor meja</label>
                <input id="table-number" maxlength="20" placeholder="Contoh: A-07">
            </div>
            <div class="field service-detail" id="platform-field" hidden>
                <label for="online-platform">Platform ojek online</label>
                <select id="online-platform">
                    <option value="">Pilih platform</option>
                    <option value="GoFood">GoFood</option>
                    <option value="GrabFood">GrabFood</option>
                    <option value="ShopeeFood">ShopeeFood</option>
                    <option value="Maxim Food">Maxim Food</option>
                    <option value="Lainnya">Lainnya</option>
                </select>
            </div>
        </div>
        <div class="cart-items" id="cart-items"><div class="cart-empty">Keranjang masih kosong.<br>Klik produk untuk menambahkan.</div></div>
        <div class="cart-summary">
            <div class="summary-row"><span>Subtotal</span><b id="subtotal">Rp 0</b></div>
            <div class="summary-row"><span>Diskon</span><div class="input-prefix" style="width:120px"><span>Rp</span><input id="discount" type="number" min="0" value="0" style="min-height:34px"></div></div>
            <div class="summary-row total"><span>Total</span><span id="total">Rp 0</span></div>
            <div class="payment-grid">
                <button class="payment active" data-payment="cash"><i class="bi bi-cash"></i><br>Tunai</button><button class="payment" data-payment="qris"><i class="bi bi-qr-code"></i><br>QRIS</button><button class="payment" data-payment="transfer"><i class="bi bi-bank"></i><br>Transfer</button><button class="payment" data-payment="deposit"><i class="bi bi-person-badge"></i><br>Deposit</button>
            </div>
            <div class="field" id="paid-field"><label>Uang diterima</label><input id="paid-amount" type="number" min="0" placeholder="0"></div>
            <button class="btn btn-primary pay-btn" id="checkout-btn" style="margin-top:12px"><i class="bi bi-printer"></i> Bayar & cetak struk</button>
        </div>
    </aside>
</div>
@endsection
@push('modals')
<div class="modal" id="scanner-modal"><div class="modal-card" style="max-width:450px"><div class="modal-head"><div><h2>Scan QR member</h2><div class="hint">Arahkan kamera ke kode QR membership.</div></div><button class="modal-close" onclick="stopScanner()">×</button></div><video id="camera" class="camera" playsinline muted></video><div id="scanner-status" class="hint" style="margin:12px 0">Menyiapkan kamera…</div><div class="field"><label>Atau masukkan kode member</label><div style="display:flex;gap:8px"><input id="manual-code" placeholder="MBR-00001"><button class="btn btn-soft" onclick="findMember(document.getElementById('manual-code').value)">Cari</button></div></div></div></div>
@endpush
@push('scripts')
<script>
const products = @json($posProducts);
const cart = new Map(); let payment = 'cash', serviceType = 'dine_in', stream = null, scanning = false;
const byId = id => products.find(p => p.id === Number(id));
document.querySelectorAll('.product-card').forEach(el => el.addEventListener('click', () => addItem(Number(el.dataset.id))));
document.querySelectorAll('.pill').forEach(el => el.addEventListener('click', () => {document.querySelectorAll('.pill').forEach(x=>x.classList.remove('active'));el.classList.add('active'); filterProducts();}));
document.getElementById('product-search').addEventListener('input', filterProducts);
document.getElementById('discount').addEventListener('input', renderCart);
document.getElementById('member-id').addEventListener('change', showMemberBalance);
document.querySelectorAll('.payment').forEach(el => el.addEventListener('click', () => {payment=el.dataset.payment;document.querySelectorAll('.payment').forEach(x=>x.classList.toggle('active',x===el));document.getElementById('paid-field').style.display=payment==='cash'?'grid':'none';}));
document.querySelectorAll('.service-option').forEach(el => el.addEventListener('click', () => {
    serviceType=el.dataset.service;
    document.querySelectorAll('.service-option').forEach(x=>x.classList.toggle('active',x===el));
    document.getElementById('table-field').hidden=serviceType!=='dine_in';
    document.getElementById('platform-field').hidden=serviceType!=='online';
}));

function filterProducts(){
    const q=document.getElementById('product-search').value.toLowerCase(), cat=document.querySelector('.pill.active').dataset.category;
    document.querySelectorAll('.product-card').forEach(el=>el.style.display=(el.dataset.search.includes(q)&&(cat==='all'||el.dataset.category===cat))?'':'none');
}
function addItem(id){const p=byId(id), item=cart.get(id)||{...p,qty:0};if(item.qty<p.stock){item.qty++;cart.set(id,item);renderCart();}}
function changeQty(id,delta){const item=cart.get(id);item.qty+=delta;if(item.qty<=0)cart.delete(id);else item.qty=Math.min(item.qty,item.stock);renderCart();}
function totals(){const subtotal=[...cart.values()].reduce((s,i)=>s+i.price*i.qty,0), discount=Math.min(Number(document.getElementById('discount').value||0),subtotal);return{subtotal,discount,total:subtotal-discount};}
function renderCart(){
    const box=document.getElementById('cart-items');
    box.innerHTML=cart.size?[...cart.values()].map(i=>`<div class="cart-line"><div><div class="cart-line-name">${i.name}</div><div class="cart-line-price">Rp ${money(i.price)} / item</div><div class="qty"><button onclick="changeQty(${i.id},-1)">−</button><span>${i.qty}</span><button onclick="changeQty(${i.id},1)">+</button></div></div><b class="money">Rp ${money(i.price*i.qty)}</b></div>`).join(''):'<div class="cart-empty">Keranjang masih kosong.<br>Klik produk untuk menambahkan.</div>';
    const t=totals();document.getElementById('cart-count').textContent=[...cart.values()].reduce((s,i)=>s+i.qty,0);document.getElementById('subtotal').textContent='Rp '+money(t.subtotal);document.getElementById('total').textContent='Rp '+money(t.total);
}
function showMemberBalance(){const option=document.getElementById('member-id').selectedOptions[0], el=document.getElementById('member-balance');if(option.value){el.style.display='block';el.textContent='Saldo deposit: Rp '+money(option.dataset.balance);}else el.style.display='none';}
document.getElementById('checkout-btn').addEventListener('click', async () => {
    if(!cart.size)return alert('Tambahkan produk ke keranjang.');
    const tableNumber=document.getElementById('table-number').value.trim(), onlinePlatform=document.getElementById('online-platform').value;
    if(serviceType==='dine_in'&&!tableNumber)return alert('Masukkan nomor meja untuk pesanan dine in.');
    if(serviceType==='online'&&!onlinePlatform)return alert('Pilih platform ojek online.');
    const btn=document.getElementById('checkout-btn'), t=totals(); if(payment==='deposit'&&!document.getElementById('member-id').value)return alert('Pilih atau scan member untuk pembayaran deposit.');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat"></i> Memproses…';
    try{
        const response=await fetch('{{ route('pos.checkout') }}',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({items:[...cart.values()].map(i=>({id:i.id,qty:i.qty})),member_id:document.getElementById('member-id').value||null,discount:t.discount,payment_method:payment,paid_amount:document.getElementById('paid-amount').value||0,service_type:serviceType,table_number:serviceType==='dine_in'?tableNumber:null,online_platform:serviceType==='online'?onlinePlatform:null})});
        const data=await response.json();if(!response.ok)throw new Error(data.message||Object.values(data.errors||{})[0]?.[0]||'Transaksi gagal.');
        cart.clear();renderCart();document.getElementById('discount').value=0;document.getElementById('paid-amount').value='';window.open(data.print_url,'_blank','width=420,height=720');alert('Transaksi '+data.invoice+' berhasil.');
        setTimeout(()=>location.reload(),700);
    }catch(e){alert(e.message)}finally{btn.disabled=false;btn.innerHTML='<i class="bi bi-printer"></i> Bayar & cetak struk'}
});
async function findMember(code){
    if(!code)return;const status=document.getElementById('scanner-status');status.textContent='Mencari member…';
    try{const r=await fetch('/member/find/'+encodeURIComponent(code),{headers:{'Accept':'application/json'}});if(!r.ok)throw new Error();const m=await r.json();document.getElementById('member-id').value=m.id;showMemberBalance();stopScanner();alert('Member '+m.name+' ditemukan.');}catch(e){status.textContent='Member tidak ditemukan. Periksa kode lalu coba lagi.'}
}
async function startScanner(){
    openModal('scanner-modal');
    if(!('BarcodeDetector' in window)){document.getElementById('scanner-status').textContent='Browser ini belum mendukung scan otomatis. Masukkan kode member secara manual.';return;}
    try{stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});const video=document.getElementById('camera');video.srcObject=stream;await video.play();scanning=true;const detector=new BarcodeDetector({formats:['qr_code']});document.getElementById('scanner-status').textContent='Kamera aktif — arahkan ke QR member.';scanFrame(detector,video);}catch(e){document.getElementById('scanner-status').textContent='Kamera tidak dapat dibuka. Izinkan akses kamera atau gunakan kode manual.'}
}
async function scanFrame(detector,video){if(!scanning)return;try{const codes=await detector.detect(video);if(codes.length){await findMember(codes[0].rawValue);return;}}catch(e){}requestAnimationFrame(()=>scanFrame(detector,video));}
function stopScanner(){scanning=false;if(stream)stream.getTracks().forEach(t=>t.stop());stream=null;closeModal('scanner-modal');}
</script>
@endpush
