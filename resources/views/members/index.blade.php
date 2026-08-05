@extends('layouts.app')
@section('title', 'Membership')
@section('content')
<div class="page-head">
    <div>
        <h1><i class="bi bi-person-vcard"></i> Membership & deposit</h1>
        <p>Alur kartu: cetak kartu lebih dulu, scan saat pendaftaran, lengkapi data, lalu serahkan ke member.</p>
    </div>
    <div class="actions">
        <button class="btn btn-soft" onclick="openModal('scan-member-modal');startMemberScanner()"><i class="bi bi-qr-code-scan"></i> Cari member</button>
        <button class="btn btn-primary" onclick="openRegistration()"><i class="bi bi-upc-scan"></i> Scan kartu & daftar</button>
    </div>
</div>

<section class="card card-pad membership-flow">
    <div class="card-title"><div><h2>Alur aktivasi kartu</h2><p>{{ $availableCards->count() }} kartu pra-cetak siap dipakai · diskon default {{ number_format(auth()->user()->tenant->member_discount_percent,1,',','.') }}%</p></div>
        @if(auth()->user()->role==='superadmin')<a class="btn btn-outline btn-sm" href="{{ route('settings') }}#aturan-bisnis"><i class="bi bi-gear"></i> Atur diskon</a>@endif
    </div>
    <div class="service-grid membership-steps">
        <div class="service-option active"><b>1</b><span>Cetak kartu QR</span></div>
        <div class="service-option active"><b>2</b><span>Scan kartu kosong</span></div>
        <div class="service-option active"><b>3</b><span>Isi data member</span></div>
        <div class="service-option active"><b>4</b><span>Serahkan kartu</span></div>
    </div>
</section>

@if($memberSummary)
<div class="grid stats" style="margin-top:16px">
    <div class="card stat"><span class="stat-icon"><i class="bi bi-people"></i></span><div class="stat-label">Total member</div><div class="stat-value">{{ number_format($memberSummary['count']) }}</div><div class="stat-note">Khusus Superadmin</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-wallet2"></i></span><div class="stat-label">Total nominal deposit</div><div class="stat-value">Rp {{ number_format($memberSummary['deposit'],0,',','.') }}</div><div class="stat-note">Saldo seluruh member</div></div>
</div>
@endif

<div class="card card-pad" style="margin-top:16px">
    <div class="card-title"><div><h2>{{ $members->total() }} member aktif</h2><p>Cari berdasarkan nama, kode kartu, atau QR.</p></div><form class="search" method="GET" style="max-width:340px"><input name="q" value="{{ $search }}" placeholder="Cari nama, kode, atau QR…"></form></div>
    <div class="table-wrap"><table><thead><tr><th>Member</th><th>Kontak / domisili</th><th>Tanggal lahir</th><th>Diskon</th><th>Kartu</th><th>Saldo deposit</th><th>Aksi</th></tr></thead><tbody>
    @forelse($members as $member)
        <tr>
            <td><div class="cell-main">{{ $member->name }}</div><div class="cell-sub">{{ $member->member_code }}</div></td>
            <td><div>{{ $member->phone ?: ($member->email ?: '—') }}</div><div class="cell-sub">{{ $member->domicile ?: 'Domisili belum diisi' }}</div></td>
            <td>{{ $member->birth_date?->translatedFormat('d M Y') ?? '—' }}</td>
            <td><span class="badge">{{ number_format($member->discount_percent,1,',','.') }}%</span></td>
            <td><button type="button" class="btn btn-outline btn-sm member-card-trigger" data-qr="{{ $member->qr_code }}" data-name="{{ $member->name }}" data-code="{{ $member->member_code }}" data-phone="{{ $member->phone }}"><i class="bi bi-person-badge"></i> Lihat</button></td>
            <td class="money">Rp {{ number_format($member->deposit_balance,0,',','.') }}</td>
            <td><button type="button" class="btn btn-soft btn-sm topup-trigger" data-url="{{ route('members.topup',$member) }}" data-name="{{ $member->name }}"><i class="bi bi-plus-circle"></i> Top up</button></td>
        </tr>
    @empty
        <tr><td colspan="7"><div class="cart-empty">Belum ada member. Scan salah satu kartu pra-cetak untuk mendaftarkan orang pertama.</div></td></tr>
    @endforelse
    </tbody></table></div>
    <div class="pagination">{{ $members->links() }}</div>
</div>
@endsection

@push('modals')
<div class="modal" id="member-modal"><div class="modal-card"><div class="modal-head"><div><h2>Scan kartu lalu isi data</h2><div class="hint">Data member baru dapat diisi setelah kartu kosong berhasil ditemukan.</div></div><button class="modal-close" onclick="stopRegistrationScanner()">×</button></div>
    <div id="registration-card-step">
        <video id="registration-camera" class="camera" playsinline muted></video>
        <div id="registration-status" class="inventory-note amber" style="margin:12px 0"><i class="bi bi-upc-scan"></i><span>Scan QR pada kartu yang sudah dicetak.</span></div>
        <div class="field"><label>Kode QR / kode kartu</label><div style="display:flex;gap:8px"><input id="registration-card-code" placeholder="Contoh: WK-MBR-P00001" autocomplete="off"><button type="button" class="btn btn-soft" onclick="lookupAvailableCard(document.getElementById('registration-card-code').value)">Verifikasi</button></div></div>
    </div>
    <form method="POST" action="{{ route('members.store') }}" class="form-grid" id="member-registration-form" style="margin-top:14px">@csrf
        <input type="hidden" name="member_card_id" id="member-card-id">
        <div class="field full"><label>Kartu terverifikasi</label><input id="verified-card-code" readonly placeholder="Scan kartu terlebih dahulu"></div>
        <fieldset id="member-data-fields" disabled class="form-grid full" style="border:0;padding:0;margin:0">
            <div class="field full"><label>Nama lengkap</label><input name="name" required></div>
            <div class="field"><label>No. HP</label><input name="phone"></div>
            <div class="field"><label>Email</label><input type="email" name="email"></div>
            <div class="field"><label>Domisili</label><input name="domicile"></div>
            <div class="field"><label>Tanggal lahir</label><input type="date" name="birth_date"></div>
            <div class="field"><label>Diskon member (%)</label><input type="number" name="discount_percent" min="0" max="100" step="0.1" value="{{ auth()->user()->tenant->member_discount_percent }}"></div>
            <div class="field full"><button class="btn btn-primary"><i class="bi bi-person-check"></i> Aktifkan kartu & simpan member</button></div>
        </fieldset>
    </form>
</div></div>

<div class="modal" id="topup-modal"><div class="modal-card" style="max-width:430px"><div class="modal-head"><div><h2>Top up deposit</h2><div id="topup-name" class="hint"></div></div><button class="modal-close" onclick="closeModal('topup-modal')">×</button></div><form method="POST" id="topup-form" class="form-grid">@csrf<div class="field full"><label>Nominal</label><input name="amount" type="text" data-money-input data-min="1000" inputmode="numeric" required></div><div class="field full"><label>Metode</label><select name="payment_method"><option value="cash">Cash</option><option value="transfer">Transfer</option></select></div><div class="field full"><button class="btn btn-primary">Simpan top up</button></div></form></div></div>

<div class="modal" id="qr-modal"><div class="modal-card" style="max-width:430px"><div class="modal-head no-print"><h2>Kartu membership</h2><button class="modal-close" onclick="closeModal('qr-modal')">×</button></div><article class="membership-card" id="printable-member-card"><div class="membership-card-head"><div class="membership-brand"><span>{{ strtoupper(substr(auth()->user()->tenant->name,0,1)) }}</span><div><b>{{ auth()->user()->tenant->name }}</b><small>MEMBER CARD</small></div></div></div><div class="membership-card-body"><div class="membership-identity"><small>Nama member</small><h3 id="qr-name"></h3><div id="qr-code"></div><div id="qr-phone" class="membership-phone"></div></div><div class="membership-card-qr"><canvas id="member-qr"></canvas></div></div></article><div class="actions no-print" style="justify-content:center;margin-top:14px"><button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Cetak kartu</button></div></div></div>

<div class="modal" id="scan-member-modal"><div class="modal-card" style="max-width:450px"><div class="modal-head"><h2>Scan / cari member aktif</h2><button class="modal-close" onclick="stopMemberScanner()">×</button></div><video id="member-camera" class="camera" playsinline muted></video><div id="member-scanner-status" class="hint">Menyiapkan kamera…</div><div class="field" style="margin-top:12px"><label>Kode QR / member</label><div style="display:flex;gap:8px"><input id="member-manual-code"><button class="btn btn-soft" onclick="lookupMember(document.getElementById('member-manual-code').value)">Cari</button></div></div></div></div>
@endpush

@push('scripts')
<script>
document.querySelectorAll('.topup-trigger').forEach(button=>button.addEventListener('click',()=>{document.getElementById('topup-form').action=button.dataset.url;document.getElementById('topup-name').textContent=button.dataset.name;openModal('topup-modal')}));
document.querySelectorAll('.member-card-trigger').forEach(button=>button.addEventListener('click',async()=>{document.getElementById('qr-name').textContent=button.dataset.name;document.getElementById('qr-code').textContent=button.dataset.code;document.getElementById('qr-phone').textContent=button.dataset.phone||'';await renderQr(document.getElementById('member-qr'),button.dataset.qr);openModal('qr-modal')}));

let registrationStream=null,registrationScanning=false;
function resetRegistration(){document.getElementById('member-registration-form').reset();document.getElementById('member-card-id').value='';document.getElementById('verified-card-code').value='';document.getElementById('member-data-fields').disabled=true;document.getElementById('registration-status').className='inventory-note amber';document.getElementById('registration-status').innerHTML='<i class="bi bi-upc-scan"></i><span>Scan QR pada kartu yang sudah dicetak.</span>'}
function openRegistration(){resetRegistration();openModal('member-modal');startRegistrationScanner()}
async function lookupAvailableCard(code){if(!code)return;const status=document.getElementById('registration-status');status.textContent='Memeriksa kartu…';try{const response=await fetch('/member/card/'+encodeURIComponent(code),{headers:{Accept:'application/json'}});if(!response.ok)throw new Error();const card=await response.json();document.getElementById('member-card-id').value=card.id;document.getElementById('verified-card-code').value=card.member_code;document.getElementById('member-data-fields').disabled=false;status.className='inventory-note';status.innerHTML='<i class="bi bi-check-circle"></i><span>Kartu '+escapeHtml(card.member_code)+' tersedia. Silakan lengkapi data orangnya.</span>';stopRegistrationCamera();document.querySelector('#member-data-fields [name=name]').focus()}catch(e){status.className='inventory-note red';status.innerHTML='<i class="bi bi-x-circle"></i><span>Kartu tidak ditemukan, sudah aktif, atau bukan milik warung ini.</span>'}}
async function startRegistrationScanner(){if(!('BarcodeDetector'in window)){document.getElementById('registration-status').innerHTML='<i class="bi bi-keyboard"></i><span>Scanner kamera tidak didukung; scan dengan barcode scanner atau masukkan kode manual.</span>';return}try{registrationStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});const video=document.getElementById('registration-camera');video.srcObject=registrationStream;await video.play();registrationScanning=true;registrationScanFrame(new BarcodeDetector({formats:['qr_code']}),video)}catch(e){document.getElementById('registration-status').innerHTML='<i class="bi bi-keyboard"></i><span>Kamera tidak dapat dibuka; gunakan scanner atau kode manual.</span>'}}
async function registrationScanFrame(detector,video){if(!registrationScanning)return;const codes=await detector.detect(video).catch(()=>[]);if(codes.length)return lookupAvailableCard(codes[0].rawValue);requestAnimationFrame(()=>registrationScanFrame(detector,video))}
function stopRegistrationCamera(){registrationScanning=false;if(registrationStream)registrationStream.getTracks().forEach(track=>track.stop());registrationStream=null}
function stopRegistrationScanner(){stopRegistrationCamera();closeModal('member-modal')}

let memberStream=null,memberScanning=false;
async function lookupMember(code){if(!code)return;try{const response=await fetch('/member/find/'+encodeURIComponent(code),{headers:{Accept:'application/json'}});if(!response.ok)throw new Error();const member=await response.json();stopMemberScanner();location.href='{{ route('members') }}?q='+encodeURIComponent(member.member_code)}catch(e){document.getElementById('member-scanner-status').textContent='Member aktif tidak ditemukan.'}}
async function startMemberScanner(){if(!('BarcodeDetector'in window)){document.getElementById('member-scanner-status').textContent='Masukkan kode secara manual.';return}try{memberStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});const video=document.getElementById('member-camera');video.srcObject=memberStream;await video.play();memberScanning=true;memberScanFrame(new BarcodeDetector({formats:['qr_code']}),video)}catch(e){document.getElementById('member-scanner-status').textContent='Kamera tidak dapat dibuka.'}}
async function memberScanFrame(detector,video){if(!memberScanning)return;const codes=await detector.detect(video).catch(()=>[]);if(codes.length)return lookupMember(codes[0].rawValue);requestAnimationFrame(()=>memberScanFrame(detector,video))}
function stopMemberScanner(){memberScanning=false;if(memberStream)memberStream.getTracks().forEach(track=>track.stop());memberStream=null;closeModal('scan-member-modal')}
</script>
@endpush
