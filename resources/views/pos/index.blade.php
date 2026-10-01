@extends('layouts.app')
@section('title', 'Kasir')
@section('content')
<div class="page-head">
    <div><h1><i class="bi bi-calculator"></i> Kasir {{ $activeStore->name }}</h1><p>Transaksi tunai, split deposit, pending bill, dan retur pengganti.</p></div>
    <div class="actions">
        @if(auth()->user()->isSupervisor())<form method="POST" action="{{ route('pos.custom-amount') }}">@csrf<input type="hidden" name="enabled" value="{{ $activeStore->allow_custom_amount?0:1 }}"><button class="btn btn-outline" title="Manager/SPV dapat mengaktifkan atau menonaktifkan fitur ini untuk cabang aktif"><i class="bi bi-toggles"></i> Custom {{ $activeStore->allow_custom_amount?'ON':'OFF' }}</button></form>@endif
        @if($reservationData->isNotEmpty() || auth()->user()->canAccess('reservations'))<button class="btn btn-soft" onclick="openModal('reservation-modal')" title="Reservasi hari ini dan tamu yang sudah datang"><i class="bi bi-calendar-check"></i> Reservasi @if($reservationData->isNotEmpty())<span class="badge amber">{{ $reservationData->count() }}</span>@endif</button>@endif
        @if($pendingBills->isNotEmpty())<button class="btn btn-soft" onclick="openModal('pending-modal')"><i class="bi bi-hourglass-split"></i> Open bill <span class="badge amber">{{ $pendingBills->count() }}</span></button>@endif
        @if($eposPrinter)<span class="badge" title="Struk dicetak otomatis ke printer Epson ePOS {{ $eposPrinter['url'] }}"><i class="bi bi-printer"></i> {{ $eposPrinter['name'] }}{{ $eposPrinter['auto_print'] ? '' : ' · manual' }}</span>@endif
        <a class="btn btn-outline" href="{{ route('pos.close') }}" target="_blank"><i class="bi bi-printer"></i> Tutup kasir</a>
    </div>
</div>
<div class="pos-layout">
    <section class="pos-products">
        <div class="search-row">
            <div class="search"><input id="product-search" placeholder="Cari nama, SKU, atau scan barcode…" autocomplete="off"></div>
            <select id="product-sort" style="max-width:190px"><option value="name-asc">A–Z</option><option value="name-desc">Z–A</option><option value="price-asc">Harga terendah</option><option value="price-desc">Harga tertinggi</option></select>
            @if($activeStore->allow_custom_amount)<button class="btn btn-soft" onclick="openModal('custom-modal')"><i class="bi bi-plus-circle"></i> Custom</button>@endif
        </div>
        <div class="category-pills"><button class="pill active" data-category="all">Semua</button>@foreach($categories as $category)<button class="pill" data-category="{{ $category->id }}">{{ $category->name }}</button>@endforeach</div>
        <div class="product-grid" id="product-grid">
            @foreach($products as $product)
                @php($stock = $product->dailyStocks->first()?->quantity ?? 0)
                @php($priceOptions = $product->pricesAt($activeStore->id))
                <button class="product-card" data-id="{{ $product->id }}" data-category="{{ $product->category_id ?: 'none' }}" data-search="{{ strtolower($product->name.' '.$product->sku.' '.$product->barcode) }}" @disabled($stock <= 0)>
                    <span class="product-card-top"><x-menu-icon :icon="$product->displayIcon()" :color="$product->category?->color" /><span class="product-category">{{ $product->category?->name ?? 'Umum' }}</span></span>
                    <div class="product-name">{{ $product->name }}</div>
                    <div class="product-meta"><span class="product-price">Rp {{ number_format($product->priceAt($activeStore->id),0,',','.') }} / {{ $product->unit }}@if($priceOptions->isNotEmpty())<small class="product-options">+{{ $priceOptions->count() }} pilihan harga</small>@endif</span><span class="product-stock">{{ $stock > 0 ? 'Stok '.\App\Support\Qty::format($stock).' '.$product->unit : 'Habis' }}</span></div>
                </button>
            @endforeach
        </div>
    </section>
    <aside class="card cart">
        <div class="cart-head"><div><h2 id="bill-title">Pesanan baru</h2><div class="hint" id="bill-reference"></div></div><span class="cart-count" id="cart-count">0</span></div>
        <div class="inventory-note amber reservation-banner" id="reservation-banner" hidden><i class="bi bi-calendar-check"></i><span id="reservation-banner-text"></span><button type="button" class="btn btn-outline btn-sm" onclick="clearReservation()" title="Lepas reservasi dari pesanan">Lepas</button></div>
        <div class="member-select">
            <div style="display:flex;gap:7px"><select id="member-id"><option value="">Pelanggan umum</option>@foreach($members as $member)<option value="{{ $member->id }}" data-balance="{{ $member->deposit_balance }}" data-discount="{{ $member->discount_percent }}">{{ $member->name }} · {{ $member->member_code }}</option>@endforeach</select><button class="btn btn-soft icon-btn" onclick="startScanner()" title="Scan QR member"><i class="bi bi-qr-code-scan"></i></button></div>
            <div id="member-balance" class="hint" style="margin-top:7px;display:none"></div>
            @if(auth()->user()->canAccess('members'))<a id="member-history-link" class="member-history-link" href="#" target="_blank" hidden><i class="bi bi-clock-history"></i> Riwayat member</a>@endif
        </div>
        <div class="order-service">
            <label>Jenis pesanan</label>
            <div class="service-grid"><button type="button" class="service-option active" data-service="dine_in"><i class="bi bi-shop"></i><span>Dine in</span></button><button type="button" class="service-option" data-service="takeaway"><i class="bi bi-bag-check"></i><span>Take away</span></button><button type="button" class="service-option" data-service="online"><i class="bi bi-scooter"></i><span>Ojek online</span></button></div>
            <div class="field service-detail" id="table-field"><label for="table-number">Nomor meja</label><input id="table-number" maxlength="20" placeholder="Contoh: A-07"></div>
            <div class="field service-detail" id="platform-field" hidden><label for="online-platform">Platform</label><select id="online-platform"><option value="">Pilih platform</option><option>GoFood</option><option>GrabFood</option><option>ShopeeFood</option><option>Maxim Food</option><option>Lainnya</option></select></div>
        </div>
        <div class="cart-items" id="cart-items"><div class="cart-empty">Keranjang masih kosong.<br>Klik produk untuk menambahkan.</div></div>
        <div class="cart-summary">
            <div class="summary-row"><span>Subtotal</span><b id="subtotal">Rp 0</b></div>
            <div class="summary-row"><select id="discount-type" style="width:135px"><option value="amount">Diskon Rp</option><option value="percent">Diskon %</option><option value="member">Diskon member</option></select><input id="discount" type="text" data-money-input data-min="0" value="0" inputmode="numeric" style="width:110px;min-height:34px"></div>
            <div class="summary-row" id="service-row" hidden><span id="service-label">Service</span><b id="service-amount">Rp 0</b></div>
            <div class="summary-row" id="tax-row" hidden><span id="tax-label">Pajak</span><b id="tax-amount">Rp 0</b></div>
            <div class="summary-row total"><span>Total</span><span id="total">Rp 0</span></div>
            <div class="summary-row" id="dp-row" hidden><span id="dp-label">DP reservasi</span><b id="dp-amount">Rp 0</b></div>
            <div class="summary-row" id="due-row" hidden><span>Sisa dibayar</span><b id="due-amount">Rp 0</b></div>
            <div class="payment-grid"><button class="payment active" data-payment="cash"><i class="bi bi-cash"></i><br>Tunai</button><button class="payment" data-payment="qris"><i class="bi bi-qr-code"></i><br>QRIS</button><button class="payment" data-payment="transfer"><i class="bi bi-bank"></i><br>Transfer</button><button class="payment" data-payment="debit"><i class="bi bi-credit-card"></i><br>Debit</button><button class="payment" data-payment="deposit"><i class="bi bi-person-badge"></i><br>Deposit</button></div>
            <div class="field" id="provider-field" hidden><label>Bank / provider penerima</label><select id="payment-provider"><option value="">Pilih bank / provider</option><optgroup label="Bank"><option>BCA</option><option>BRI</option><option>BNI</option><option>Mandiri</option><option>CIMB Niaga</option><option>Permata</option><option>Bank Jago</option><option>SeaBank</option></optgroup><optgroup label="QRIS / e-wallet"><option>QRIS BCA</option><option>QRIS BRI</option><option>QRIS BNI</option><option>QRIS Mandiri</option><option>GoPay Merchant</option><option>OVO Merchant</option><option>DANA Bisnis</option><option>ShopeePay Merchant</option></optgroup></select></div>
            <div class="field" id="deposit-field" hidden><label>Deposit yang dipakai <small>(boleh sebagian)</small></label><input id="deposit-amount" type="text" data-money-input data-min="0" inputmode="numeric" value="0"></div>
            <div class="field" id="paid-field"><label>Uang diterima</label><input id="paid-amount" type="text" data-money-input data-min="0" inputmode="numeric" placeholder="0"></div>
            <label class="inventory-note amber" style="margin-top:10px"><input type="checkbox" id="replacement-mode" style="width:auto;min-height:auto"><span><b>Retur / transaksi pengganti</b><br>Wajib otorisasi Manager/SPV, tanpa pembayaran.</span></label>
            <div class="field" id="replacement-pin-field" hidden><label>PIN Manager/SPV</label><input id="approval-pin" type="password" inputmode="numeric" maxlength="12"></div>
            <div class="actions" style="margin-top:12px"><button class="btn btn-soft" id="hold-btn"><i class="bi bi-hourglass"></i> Pending</button><button class="btn btn-primary pay-btn" id="checkout-btn"><i class="bi bi-printer"></i> Bayar & cetak</button></div>
        </div>
    </aside>
</div>
@endsection

@push('modals')
<div class="modal" id="price-picker-modal"><div class="modal-card" style="max-width:440px"><div class="modal-head"><div><h2 id="price-picker-title">Pilih harga</h2><div class="hint">Satu SKU memiliki beberapa pilihan harga.</div></div><button class="modal-close" onclick="closeModal('price-picker-modal')">×</button></div><div class="price-picker-list" id="price-picker-list"></div></div></div>
<div class="modal" id="custom-modal"><div class="modal-card" style="max-width:440px"><div class="modal-head"><h2>Custom amount</h2><button class="modal-close" onclick="closeModal('custom-modal')">×</button></div><div class="form-grid"><div class="field full"><label>Nama produk</label><input id="custom-name" maxlength="120" placeholder="Wajib tampil di nota"></div><div class="field full"><label>Harga</label><input id="custom-price" type="text" data-money-input data-min="0" inputmode="numeric"></div><div class="field full"><button class="btn btn-primary" onclick="addCustomItem()">Tambahkan</button></div></div></div></div>
<div class="modal" id="reservation-modal"><div class="modal-card"><div class="modal-head"><div><h2>Reservasi</h2><div class="hint">Pilih reservasi tamu yang datang. Nomor meja terisi otomatis dan DP memotong tagihan.</div></div><button class="modal-close" onclick="closeModal('reservation-modal')">×</button></div><div class="list reservation-pick">@forelse($reservationData as $reservation)<button type="button" class="list-item" style="display:flex;align-items:center;gap:10px;width:100%;border:0;background:transparent;text-align:left" onclick="useReservation({{ $reservation['id'] }})"><span class="list-icon"><i class="bi bi-calendar-check"></i></span><span class="list-body"><b>{{ $reservation['time'] }} · {{ $reservation['name'] }}</b><small>{{ $reservation['code'] }} · {{ $reservation['guests'] }} orang{{ $reservation['table'] ? ' · Meja '.$reservation['table'] : '' }}{{ $reservation['status']==='arrived' ? ' · sudah datang' : '' }}{{ $reservation['pending_id'] ? ' · ada open bill' : '' }}</small></span><span class="money">{{ $reservation['dp'] > 0 ? 'DP Rp '.number_format($reservation['dp'],0,',','.') : 'Tanpa DP' }}</span></button>@empty<div class="cart-empty">Belum ada reservasi hari ini.</div>@endforelse</div>@if(auth()->user()->canAccess('reservations'))<div class="actions" style="margin-top:12px"><a class="btn btn-outline" href="{{ route('reservations') }}" target="_blank"><i class="bi bi-calendar-plus"></i> Kelola reservasi</a></div>@endif</div></div>
<div class="modal" id="pending-modal"><div class="modal-card"><div class="modal-head"><div><h2>Open bill</h2><div class="hint">Pesanan tersimpan, belum memotong stok. Bill yang dibuka lalu disimpan kembali akan diperbarui, bukan diduplikasi.</div></div><button class="modal-close" onclick="closeModal('pending-modal')">×</button></div><div class="list">@foreach($pendingBills as $bill)<div class="list-item" style="display:flex;align-items:center;gap:8px"><button style="display:flex;align-items:center;gap:10px;flex:1;border:0;background:transparent;text-align:left" onclick="loadPending({{ $bill->id }})"><span class="list-icon"><i class="bi bi-receipt"></i></span><span class="list-body"><b>{{ $bill->invoice_no }}</b><small>{{ $bill->member?->name ?? 'Umum' }} · @qty($bill->items->sum('quantity')) item · {{ $bill->transacted_at->format('H:i') }}</small></span><span class="money">Rp {{ number_format($bill->total,0,',','.') }}</span></button><form method="POST" action="{{ route('pos.pending.cancel',$bill) }}" onsubmit="const reason=prompt('Alasan membatalkan bill?');if(!reason)return false;this.reason.value=reason">@csrf @method('DELETE')<input type="hidden" name="reason"><button class="btn btn-danger btn-sm" title="Batalkan bill"><i class="bi bi-x-lg"></i></button></form></div>@endforeach</div></div></div>
<div class="modal" id="scanner-modal"><div class="modal-card" style="max-width:450px"><div class="modal-head"><div><h2>Scan QR member</h2><div class="hint">Arahkan kamera ke kode QR membership.</div></div><button class="modal-close" onclick="stopScanner()">×</button></div><video id="camera" class="camera" playsinline muted></video><div id="scanner-status" class="hint" style="margin:12px 0">Menyiapkan kamera…</div><div class="field"><label>Atau masukkan kode member</label><div style="display:flex;gap:8px"><input id="manual-code" placeholder="MBR-00001"><button class="btn btn-soft" onclick="findMember(document.getElementById('manual-code').value)">Cari</button></div></div></div></div>
@endpush

@push('scripts')
<script>
const products=@json($posProducts);
const pendingBills=@json($pendingBillData);
const reservations=@json($reservationData);
window.posReservation=null;
const chargeConfig=@json($chargeConfig);
document.addEventListener('DOMContentLoaded',()=>window.PosPrinter?.configure(@json($eposPrinter)));
const cart=new Map();let payment='cash',serviceType='dine_in',stream=null,scanning=false,pendingId=null,customSequence=0;
const byId=id=>products.find(p=>p.id===Number(id));
document.querySelectorAll('.product-card').forEach(el=>el.addEventListener('click',()=>openProduct(Number(el.dataset.id))));
document.querySelectorAll('.pill').forEach(el=>el.addEventListener('click',()=>{document.querySelectorAll('.pill').forEach(x=>x.classList.remove('active'));el.classList.add('active');filterProducts()}));
document.getElementById('product-search').addEventListener('input',filterProducts);document.getElementById('product-sort').addEventListener('change',sortProducts);
document.getElementById('discount').addEventListener('input',renderCart);document.getElementById('discount-type').addEventListener('change',renderCart);document.getElementById('deposit-amount').addEventListener('input',renderPayment);
document.getElementById('member-id').addEventListener('change',showMemberBalance);
document.querySelectorAll('.payment').forEach(el=>el.addEventListener('click',()=>{payment=el.dataset.payment;document.querySelectorAll('.payment').forEach(x=>x.classList.toggle('active',x===el));if(payment==='deposit')setMoneyInputValue(document.getElementById('deposit-amount'),amountDue());renderPayment()}));
document.querySelectorAll('.service-option').forEach(el=>el.addEventListener('click',()=>{serviceType=el.dataset.service;document.querySelectorAll('.service-option').forEach(x=>x.classList.toggle('active',x===el));document.getElementById('table-field').hidden=serviceType!=='dine_in';document.getElementById('platform-field').hidden=serviceType!=='online';cart.forEach(item=>{if(!item.custom){const p=byId(item.id);if(p)item.price=priceOf(p,item.priceId)}});renderCart()}));
document.getElementById('replacement-mode').addEventListener('change',event=>{document.getElementById('replacement-pin-field').hidden=!event.target.checked;renderCart()});
function filterProducts(){const q=document.getElementById('product-search').value.toLowerCase(),cat=document.querySelector('.pill.active').dataset.category;document.querySelectorAll('.product-card').forEach(el=>el.style.display=(el.dataset.search.includes(q)&&(cat==='all'||el.dataset.category===cat))?'':'none')}
function sortProducts(){const mode=document.getElementById('product-sort').value,[field,direction]=mode.split('-');[...document.querySelectorAll('.product-card')].sort((a,b)=>{const pa=byId(a.dataset.id),pb=byId(b.dataset.id),av=field==='price'?pa.price:pa.name,bv=field==='price'?pb.price:pb.name;return (typeof av==='string'?av.localeCompare(bv):av-bv)*(direction==='asc'?1:-1)}).forEach(el=>document.getElementById('product-grid').appendChild(el))}
function priceOf(p,priceId){const option=priceId?p.prices.find(x=>x.id===priceId):null;if(option)return serviceType==='online'?option.online_price:option.price;return serviceType==='online'?p.online_price:p.price}
function qtyInCart(id,exceptKey){return [...cart.values()].filter(i=>!i.custom&&i.id===id&&i.key!==exceptKey).reduce((sum,i)=>sum+i.qty,0)}
function maxQty(item){return item.custom?item.stock:Math.max(0,item.stock-qtyInCart(item.id,item.key))}
function openProduct(id){const p=byId(id);if(!p.prices.length)return addItem(id,null);document.getElementById('price-picker-title').textContent=p.name;document.getElementById('price-picker-list').innerHTML=[{id:null,label:'Harga normal'},...p.prices].map(o=>`<button type="button" class="price-choice" onclick="pickPrice(${p.id},${o.id===null?'null':o.id})"><span>${escapeHtml(o.label)}</span><b>Rp ${money(priceOf(p,o.id))}</b></button>`).join('');openModal('price-picker-modal')}
function pickPrice(id,priceId){closeModal('price-picker-modal');addItem(id,priceId)}
function addItem(id,priceId=null){const p=byId(id),key='p'+id+(priceId?'-'+priceId:''),option=priceId?p.prices.find(x=>x.id===priceId):null,item=cart.get(key)||{...p,key,priceId,label:option?.label||null,name:option?p.name+' ('+option.label+')':p.name,qty:0,custom:false},available=maxQty(item);if(item.qty<available){item.qty=Math.min(item.qty+Number(p.increment||1),available);item.price=priceOf(p,priceId);cart.set(key,item);renderCart()}}
function addCustomItem(){const name=document.getElementById('custom-name').value.trim(),price=moneyValue(document.getElementById('custom-price'));if(!name||price<0)return alert('Nama dan harga wajib diisi.');const key='c'+(++customSequence);cart.set(key,{key,id:null,name,price,qty:1,stock:999999,unit:'item',step:1,increment:1,custom:true});closeModal('custom-modal');document.getElementById('custom-name').value='';setMoneyInputValue(document.getElementById('custom-price'),'');renderCart()}
function changeQty(key,direction){const item=cart.get(key);item.qty+=direction*Number(item.increment||1);if(item.qty<=0)cart.delete(key);else item.qty=Math.min(item.qty,maxQty(item));renderCart()}
function setQty(key,value){const item=cart.get(key),qty=Number(value);if(!Number.isFinite(qty)||qty<=0)cart.delete(key);else item.qty=Math.min(qty,maxQty(item));renderCart()}
// Rumus sama dengan Store::chargesFor(): service dari total setelah diskon, pajak dari (total setelah diskon + service).
function totals(){const subtotal=[...cart.values()].reduce((s,i)=>s+i.price*i.qty,0),type=document.getElementById('discount-type').value;let value=moneyValue(document.getElementById('discount'));if(type==='member'){const option=document.getElementById('member-id').selectedOptions[0];value=Number(option?.dataset.discount||0)}const discount=Math.min(type==='amount'?value:subtotal*Math.min(value,100)/100,subtotal),base=subtotal-discount,replacement=document.getElementById('replacement-mode').checked,servicePercent=!replacement&&chargeConfig.service_types.includes(serviceType)?chargeConfig.service_percent:0,taxPercent=!replacement&&chargeConfig.tax_types.includes(serviceType)?chargeConfig.tax_percent:0,service=base>0?Math.round(base*servicePercent/100):0,tax=base>0?Math.round((base+service)*taxPercent/100):0;return{subtotal,discount,service,tax,servicePercent,taxPercent,total:base+service+tax,value,type}}
function renderCart(){const box=document.getElementById('cart-items');box.innerHTML=cart.size?[...cart.values()].map(i=>`<div class="cart-line"><div><div class="cart-line-name">${escapeHtml(i.name)}${i.custom?' <span class="badge amber">Custom</span>':''}</div><div class="cart-line-price">Rp ${money(i.price)} / ${escapeHtml(i.unit||'item')}</div><div class="qty"><button onclick="changeQty('${i.key}',-1)">−</button><input type="number" min="${i.step||1}" step="${i.step||1}" max="${i.stock}" value="${i.qty}" onchange="setQty('${i.key}',this.value)" style="width:78px;min-height:30px;text-align:center"><span>${escapeHtml(i.unit||'item')}</span><button onclick="changeQty('${i.key}',1)">+</button></div></div><b class="money">Rp ${money(i.price*i.qty)}</b></div>`).join(''):'<div class="cart-empty">Keranjang masih kosong.<br>Klik produk untuk menambahkan.</div>';const t=totals();document.getElementById('cart-count').textContent=cart.size;document.getElementById('subtotal').textContent='Rp '+money(t.subtotal);document.getElementById('service-row').hidden=!t.service;document.getElementById('service-label').textContent='Service ('+Number(t.servicePercent)+'%)';document.getElementById('service-amount').textContent='Rp '+money(t.service);document.getElementById('tax-row').hidden=!t.tax;document.getElementById('tax-label').textContent=chargeConfig.tax_label+' ('+Number(t.taxPercent)+'%)';document.getElementById('tax-amount').textContent='Rp '+money(t.tax);document.getElementById('total').textContent='Rp '+money(t.total);if(t.type==='member')document.getElementById('discount').value=String(t.value);renderPayment()}
// DP reservasi memotong total; sisanya dibayar lewat kanal biasa (rumus sama dengan checkout di server).
function reservationDp(){return Math.min(Number(window.posReservation?.dp||0),totals().total)}
function amountDue(){return totals().total-reservationDp()}
function renderPayment(){const member=!!document.getElementById('member-id').value,replacement=document.getElementById('replacement-mode').checked,dp=reservationDp(),due=amountDue();document.getElementById('dp-row').hidden=!dp;document.getElementById('due-row').hidden=!dp;document.getElementById('dp-label').textContent='DP reservasi '+(window.posReservation?.code||'');document.getElementById('dp-amount').textContent='−Rp '+money(dp);document.getElementById('due-amount').textContent='Rp '+money(due);document.getElementById('deposit-field').hidden=!member||replacement;document.getElementById('provider-field').hidden=!['qris','transfer','debit'].includes(payment)||replacement||(dp>0&&due<=0);document.getElementById('paid-field').hidden=replacement||(dp>0&&due<=0)||!(['cash','deposit'].includes(payment)&&moneyValue(document.getElementById('deposit-amount'))<due);}
function renderReservation(){const r=window.posReservation,banner=document.getElementById('reservation-banner');banner.hidden=!r;if(r)document.getElementById('reservation-banner-text').textContent=r.code+' · '+r.name+' · '+r.guests+' orang'+(r.dp>0?' · DP Rp '+money(r.dp):'');renderCart()}
function useReservation(id){const r=reservations.find(row=>row.id===id);closeModal('reservation-modal');if(r.pending_id&&pendingBills.some(bill=>bill.id===r.pending_id))return loadPending(r.pending_id);if(r.pending_id)return alert('Reservasi ini sudah memiliki open bill.');window.posReservation=r;document.querySelector('.service-option[data-service="dine_in"]').click();if(r.table)document.getElementById('table-number').value=r.table;renderReservation()}
function clearReservation(){window.posReservation=null;renderReservation()}
function showMemberBalance(){const option=document.getElementById('member-id').selectedOptions[0],el=document.getElementById('member-balance'),history=document.getElementById('member-history-link');if(history){history.hidden=!option.value;history.href=option.value?'/member/'+option.value+'/riwayat':'#'}if(option.value){el.style.display='block';el.textContent='Saldo deposit Rp '+money(option.dataset.balance)+' · Diskon '+Number(option.dataset.discount||0)+'%';document.getElementById('deposit-field').hidden=false;if(Number(option.dataset.discount)>0){document.getElementById('discount-type').value='member';setMoneyInputValue(document.getElementById('discount'),option.dataset.discount)}}else{el.style.display='none';setMoneyInputValue(document.getElementById('deposit-amount'),0);if(document.getElementById('discount-type').value==='member')document.getElementById('discount-type').value='amount'}renderCart()}
function orderPayload(){const t=totals(),dp=Math.min(Number(window.posReservation?.dp||0),t.total),due=t.total-dp,deposit=Math.min(moneyValue(document.getElementById('deposit-amount')),due),remaining=due-deposit,payments=[];if(deposit>0)payments.push({method:'deposit',amount:deposit});if(remaining>0){payments.push({method:payment==='deposit'?'cash':payment,provider:document.getElementById('payment-provider').value.trim()||null,amount:['cash','deposit'].includes(payment)?moneyValue(document.getElementById('paid-amount')):remaining})}return{items:[...cart.values()].map(i=>({id:i.id,price_id:i.custom?null:(i.priceId||null),qty:i.qty,name:i.custom?i.name:null,price:i.custom?i.price:null})),member_id:document.getElementById('member-id').value||null,discount_type:t.type,discount_value:t.value,payments,service_type:serviceType,table_number:serviceType==='dine_in'?document.getElementById('table-number').value.trim():null,online_platform:serviceType==='online'?document.getElementById('online-platform').value:null,pending_transaction_id:pendingId,reservation_id:window.posReservation?.id||null,transaction_type:document.getElementById('replacement-mode').checked?'replacement':'sale',approval_pin:document.getElementById('approval-pin').value||null}}
async function submitOrder(url, hold = false) {
    if (!cart.size) return alert('Tambahkan produk ke keranjang.');
    if (!hold && payment === 'deposit' && !document.getElementById('member-id').value) return alert('Pilih atau scan member untuk pembayaran deposit.');
    const payload = orderPayload();
    const due = totals().total - Math.min(Number(window.posReservation?.dp || 0), totals().total);
    if (!hold && payload.transaction_type !== 'replacement' && payload.payments.reduce((sum, row) => sum + row.amount, 0) < due) return alert('Nominal pembayaran kurang. Isi uang tunai yang diterima untuk melunasi tagihan.');
    if (serviceType === 'dine_in' && !payload.table_number) return alert('Masukkan nomor meja.');
    if (serviceType === 'online' && !payload.online_platform) return alert('Pilih platform online.');
    const providerPayment = payload.payments.find(p => ['qris', 'transfer', 'debit'].includes(p.method));
    if (!hold && providerPayment && !providerPayment.provider) return alert('Isi bank/provider penerima.');
    const btn = hold ? document.getElementById('hold-btn') : document.getElementById('checkout-btn');
    btn.disabled = true;
    // Printer Epson ePOS mencetak langsung tanpa jendela struk browser.
    const epos = !hold && Boolean(window.PosPrinter?.isReady?.());
    // Open during the click gesture so the payment request does not trigger a popup blocker.
    const printWindow = hold || epos ? null : window.open('about:blank', '_blank', 'width=420,height=720');
    if (printWindow) printWindow.document.body.textContent = 'Menyiapkan struk…';
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content},
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Transaksi gagal.');
        if (data.print_url) {
            const printUrl = new URL(data.print_url, location.origin);
            printUrl.searchParams.set('autoprint', '1');
            if (epos) {
                try {
                    if (!data.epos) throw new Error('Data cetak Epson tidak tersedia.');
                    await window.PosPrinter.printJobs(data.epos);
                } catch (printError) {
                    // Transaksi sudah tersimpan: jangan ulangi pembayaran, cukup buka struk browser.
                    alert('Transaksi ' + data.invoice + ' tersimpan, tetapi struk gagal dicetak ke printer Epson. ' + printError.message + ' Struk dibuka di browser.');
                    location.assign(printUrl.href);
                    return;
                }
            } else if (printWindow && !printWindow.closed) {
                printWindow.location.replace(printUrl.href);
            } else {
                // Payment is saved: show its receipt here when the popup was blocked or closed.
                location.assign(printUrl.href);
                return;
            }
        } else if (printWindow) {
            printWindow.close();
        }
        if (hold) alert('Bill ' + data.invoice + ' berhasil.');
        location.reload();
    } catch (error) {
        if (printWindow && !printWindow.closed) printWindow.close();
        alert(error.message);
    } finally {
        btn.disabled = false;
    }
}
document.getElementById('checkout-btn').addEventListener('click',()=>submitOrder('{{ route('pos.checkout') }}'));document.getElementById('hold-btn').addEventListener('click',()=>submitOrder('{{ route('pos.pending') }}',true));
function loadPending(id){const bill=pendingBills.find(row=>row.id===id);cart.clear();bill.items.forEach((item,index)=>{const p=item.id?byId(item.id):null,key=item.id?'p'+item.id+(item.price_id?'-'+item.price_id:''):'pending'+index;cart.set(key,{key,id:item.id,priceId:item.price_id||null,label:item.label||null,prices:p?.prices||[],name:item.name,price:item.price,qty:item.qty,stock:p?.stock||999999,unit:p?.unit||'item',step:p?.step||1,increment:p?.increment||1,custom:item.custom})});pendingId=bill.id;document.getElementById('bill-title').textContent='Lanjutkan open bill';document.getElementById('bill-reference').textContent=bill.invoice;document.getElementById('member-id').value=bill.member_id||'';document.getElementById('discount-type').value=bill.discount_type||'amount';setMoneyInputValue(document.getElementById('discount'),bill.discount_value||0);document.getElementById('table-number').value=bill.table_number||'';document.getElementById('online-platform').value=bill.online_platform||'';document.querySelector(`.service-option[data-service="${bill.service_type}"]`).click();showMemberBalance();closeModal('pending-modal');window.posReservation=bill.reservation||null;renderReservation()}
async function findMember(code){if(!code)return;const status=document.getElementById('scanner-status');status.textContent='Mencari member…';try{const r=await fetch('/member/find/'+encodeURIComponent(code),{headers:{Accept:'application/json'}});if(!r.ok)throw new Error();const m=await r.json();document.getElementById('member-id').value=m.id;showMemberBalance();stopScanner();alert('Member '+m.name+' ditemukan.')}catch(e){status.textContent='Member tidak ditemukan.'}}
async function startScanner(){openModal('scanner-modal');if(!('BarcodeDetector'in window)){document.getElementById('scanner-status').textContent='Scan otomatis tidak didukung. Masukkan kode manual.';return}try{stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});const video=document.getElementById('camera');video.srcObject=stream;await video.play();scanning=true;scanFrame(new BarcodeDetector({formats:['qr_code']}),video)}catch(e){document.getElementById('scanner-status').textContent='Kamera tidak dapat dibuka.'}}
async function scanFrame(detector,video){if(!scanning)return;try{const codes=await detector.detect(video);if(codes.length)return findMember(codes[0].rawValue)}catch(e){}requestAnimationFrame(()=>scanFrame(detector,video))}function stopScanner(){scanning=false;if(stream)stream.getTracks().forEach(t=>t.stop());stream=null;closeModal('scanner-modal')}function escapeHtml(value){const div=document.createElement('div');div.textContent=value;return div.innerHTML}
document.addEventListener('DOMContentLoaded',()=>{renderCart();sortProducts()});
</script>
@endpush
