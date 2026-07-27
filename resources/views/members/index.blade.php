@extends('layouts.app')
@section('title', 'Membership')
@section('content')
<div class="page-head">
    <div><h1><i class="bi bi-person-vcard"></i> Membership & deposit</h1><p>Kelola kartu member, saldo deposit, dan QR untuk pembayaran cepat.</p></div>
    <div class="actions"><button class="btn btn-soft" onclick="openModal('scan-member-modal');startMemberScanner()"><i class="bi bi-qr-code-scan"></i> QR Scan</button><button class="btn btn-primary" onclick="openModal('member-modal')"><i class="bi bi-person-plus"></i> Member baru</button></div>
</div>
<div class="card card-pad"><div class="card-title"><div><h2>{{ $members->total() }} member</h2><p>Saldo deposit tersimpan aman per tenant</p></div></div><div class="table-wrap"><table><thead><tr><th>Member</th><th>Kontak</th><th>Kartu member</th><th>Saldo deposit</th><th>Aksi</th></tr></thead><tbody>
@forelse($members as $member)
    <tr>
        <td><div class="cell-main">{{ $member->name }}</div><div class="cell-sub">{{ $member->member_code }}</div></td>
        <td>{{ $member->phone ?: ($member->email ?: '—') }}</td>
        <td><button type="button" class="btn btn-outline btn-sm member-card-trigger" data-qr="{{ $member->qr_code }}" data-name="{{ $member->name }}" data-code="{{ $member->member_code }}" data-phone="{{ $member->phone }}"><i class="bi bi-person-badge"></i> Lihat kartu</button></td>
        <td class="money">Rp {{ number_format($member->deposit_balance,0,',','.') }}</td>
        <td><button type="button" class="btn btn-soft btn-sm topup-trigger" data-url="{{ route('members.topup', $member) }}" data-name="{{ $member->name }}" data-code="{{ $member->member_code }}"><i class="bi bi-plus-circle"></i> Top up</button></td>
    </tr>
@empty
    <tr><td colspan="5"><div class="cart-empty">Belum ada member.</div></td></tr>
@endforelse
</tbody></table></div><div class="pagination">{{ $members->links() }}</div></div>
@endsection

@push('modals')
<div class="modal" id="member-modal"><div class="modal-card"><div class="modal-head"><h2>Tambah member</h2><button class="modal-close" onclick="closeModal('member-modal')">×</button></div><form method="POST" action="{{ route('members.store') }}" class="form-grid">@csrf<div class="field full"><label>Nama lengkap</label><input name="name" required></div><div class="field"><label>No. HP</label><input name="phone"></div><div class="field"><label>Email</label><input type="email" name="email"></div><div class="field full"><button class="btn btn-primary">Buat membership</button></div></form></div></div>

<div class="modal" id="topup-modal"><div class="modal-card" style="max-width:440px"><div class="modal-head"><div><h2>Top up deposit</h2><div id="topup-name" class="hint"></div></div><button class="modal-close" onclick="closeModal('topup-modal')">×</button></div><form id="topup-form" method="POST" class="form-grid">@csrf
    <div class="field full"><label>Nominal top up</label><div class="input-prefix"><span>Rp</span><input id="topup-amount" type="text" name="amount" data-money-input data-min="1000" required placeholder="50.000" inputmode="numeric" autocomplete="off"></div></div>
    <div class="field full"><div class="topup-presets"><button type="button" data-amount="25000">Rp25 ribu</button><button type="button" data-amount="50000">Rp50 ribu</button><button type="button" data-amount="100000">Rp100 ribu</button></div></div>
    <div class="field full"><button class="btn btn-primary" id="topup-submit"><i class="bi bi-wallet2"></i> Tambahkan saldo</button></div>
</form></div></div>

<div class="modal membership-print-modal" id="qr-modal"><div class="modal-card membership-modal-card"><div class="modal-head no-print"><div><h2>Kartu membership</h2><div class="hint">Tunjukkan QR saat transaksi.</div></div><button class="modal-close" onclick="closeModal('qr-modal')">×</button></div>
    <article class="membership-card" id="printable-member-card">
        <div class="membership-orb orb-one"></div><div class="membership-orb orb-two"></div>
        <header class="membership-card-head">
            <div class="membership-brand">@if(auth()->user()->tenant->logo_path)<img src="{{ asset('storage/'.auth()->user()->tenant->logo_path) }}">@else<span>{{ strtoupper(substr(auth()->user()->tenant->name, 0, 1)) }}</span>@endif<div><b>{{ auth()->user()->tenant->name }}</b><small>MEMBER CARD</small></div></div>
            <i class="bi bi-stars"></i>
        </header>
        <div class="membership-card-body">
            <div class="membership-identity"><small>Nama member</small><h3 id="qr-name"></h3><div id="qr-code"></div><div id="qr-phone" class="membership-phone"></div></div>
            <div class="membership-card-qr"><canvas id="member-qr"></canvas></div>
        </div>
    </article>
    <div class="actions no-print membership-actions"><button class="btn btn-outline" onclick="window.print()"><i class="bi bi-printer"></i> Cetak kartu</button></div>
</div></div>

<div class="modal" id="scan-member-modal"><div class="modal-card" style="max-width:440px"><div class="modal-head"><h2>Scan QR member</h2><button class="modal-close" onclick="stopMemberScanner()">×</button></div><video id="member-camera" class="camera" playsinline muted></video><div id="member-scan-result" class="hint" style="margin-top:12px">Menyiapkan kamera…</div></div></div>
@endpush

@push('scripts')
<script>
document.querySelectorAll('.topup-trigger').forEach(button => button.addEventListener('click', () => {
    document.getElementById('topup-form').action=button.dataset.url;
    document.getElementById('topup-name').textContent=button.dataset.name+' · '+button.dataset.code;
    document.getElementById('topup-amount').value='';
    openModal('topup-modal');
    setTimeout(()=>document.getElementById('topup-amount').focus(),100);
}));
document.querySelectorAll('.topup-presets button').forEach(button => button.addEventListener('click', () => {
    setMoneyInputValue(document.getElementById('topup-amount'),button.dataset.amount);
}));
document.getElementById('topup-form').addEventListener('submit', () => {
    const button=document.getElementById('topup-submit');button.disabled=true;button.innerHTML='<i class="bi bi-arrow-repeat"></i> Memproses…';
});
document.querySelectorAll('.member-card-trigger').forEach(button => button.addEventListener('click', () => {
    renderQr(document.getElementById('member-qr'),button.dataset.qr);
    document.getElementById('qr-name').textContent=button.dataset.name;
    document.getElementById('qr-code').textContent=button.dataset.code;
    document.getElementById('qr-phone').textContent=button.dataset.phone||'Member setia';
    openModal('qr-modal');
}));
let memberStream=null, memberScanning=false;
async function startMemberScanner(){if(!('BarcodeDetector'in window)){document.getElementById('member-scan-result').textContent='Browser ini belum mendukung scan QR otomatis.';return}try{memberStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});const v=document.getElementById('member-camera');v.srcObject=memberStream;await v.play();memberScanning=true;scanMemberFrame(new BarcodeDetector({formats:['qr_code']}),v)}catch(e){document.getElementById('member-scan-result').textContent='Kamera tidak dapat dibuka. Pastikan izin kamera aktif.'}}
async function scanMemberFrame(d,v){if(!memberScanning)return;const c=await d.detect(v).catch(()=>[]);if(c.length){const r=await fetch('/member/find/'+encodeURIComponent(c[0].rawValue),{headers:{Accept:'application/json'}});if(r.ok){const m=await r.json();document.getElementById('member-scan-result').innerHTML='<b>'+m.name+'</b><br>'+m.member_code+' · Saldo Rp '+money(m.deposit_balance);memberScanning=false;return}}requestAnimationFrame(()=>scanMemberFrame(d,v))}
function stopMemberScanner(){memberScanning=false;if(memberStream)memberStream.getTracks().forEach(t=>t.stop());memberStream=null;closeModal('scan-member-modal')}
</script>
@endpush
