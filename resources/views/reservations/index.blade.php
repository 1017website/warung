@extends('layouts.app')
@section('title', 'Reservasi')
@section('content')
@php($statusBadge = ['booked' => '', 'arrived' => 'amber', 'completed' => 'gray', 'cancelled' => 'red', 'no_show' => 'red'])
<div class="page-head">
    <div><h1><i class="bi bi-calendar-check"></i> Reservasi meja</h1><p>Catat reservasi dan DP tamu {{ $activeStore->name }}. Saat tamu datang, buka reservasinya di Kasir, lalu DP otomatis memotong tagihan.</p></div>
    <div class="actions">
        @if(auth()->user()->canAccess('pos'))<a class="btn btn-outline" href="{{ route('pos') }}"><i class="bi bi-calculator"></i> Buka Kasir</a>@endif
        <button class="btn btn-primary" onclick="openReservationForm()"><i class="bi bi-plus-circle"></i> Reservasi baru</button>
    </div>
</div>

<div class="grid stats">
    <div class="card stat"><span class="stat-icon"><i class="bi bi-calendar-event"></i></span><div class="stat-label">Reservasi {{ $date->isToday() ? 'hari ini' : $date->translatedFormat('d M') }}</div><div class="stat-value">{{ $summary['count'] }}</div><div class="stat-note">Tanpa yang dibatalkan</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-people"></i></span><div class="stat-label">Jumlah tamu</div><div class="stat-value">{{ number_format($summary['guests'],0,',','.') }}</div><div class="stat-note">Dipesan, datang, dan selesai</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-hourglass-split"></i></span><div class="stat-label">Belum selesai</div><div class="stat-value">{{ $summary['open'] }}</div><div class="stat-note">Status dipesan / tamu datang</div></div>
    <div class="card stat"><span class="stat-icon"><i class="bi bi-cash-coin"></i></span><div class="stat-label">DP diterima</div><div class="stat-value">Rp {{ number_format($summary['dp'],0,',','.') }}</div><div class="stat-note">Tidak termasuk DP yang dikembalikan</div></div>
</div>

<section class="card card-pad">
    <div class="card-title"><div><h2>{{ $date->translatedFormat('l, d F Y') }}</h2><p>{{ $reservations->count() }} reservasi · urut jam kedatangan</p></div>
        <div class="actions"><a class="btn btn-outline btn-sm" href="{{ route('reservations', ['date' => $date->copy()->subDay()->toDateString()]) }}" title="Hari sebelumnya"><i class="bi bi-chevron-left"></i></a><a class="btn btn-soft btn-sm" href="{{ route('reservations') }}">Hari ini</a><a class="btn btn-outline btn-sm" href="{{ route('reservations', ['date' => $date->copy()->addDay()->toDateString()]) }}" title="Hari berikutnya"><i class="bi bi-chevron-right"></i></a></div>
    </div>
    <form method="GET" class="asset-filter">
        <div class="search"><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama, no. HP, atau kode…"></div>
        <input type="date" name="date" value="{{ $date->toDateString() }}" aria-label="Tanggal">
        <select name="status" aria-label="Status"><option value="all">Semua status</option><option value="open" @selected($status==='open')>Belum selesai</option>@foreach(\App\Models\Reservation::STATUSES as $key => $label)<option value="{{ $key }}" @selected($status===$key)>{{ $label }}</option>@endforeach</select>
        <button class="btn btn-soft"><i class="bi bi-funnel"></i> Terapkan</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Jam</th><th>Tamu</th><th>Meja</th><th>DP</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($reservations as $reservation)
        <tr>
            <td><div class="cell-main">{{ $reservation->reserved_at->format('H:i') }}</div><div class="cell-sub">{{ $reservation->code }}</div></td>
            <td><div class="cell-main">{{ $reservation->customer_name }}</div><div class="cell-sub">{{ $reservation->guests }} orang{{ $reservation->phone ? ' · '.$reservation->phone : '' }}</div>@if($reservation->notes)<div class="cell-sub">{{ $reservation->notes }}</div>@endif</td>
            <td>{{ $reservation->table_number ?: '—' }}</td>
            <td>@if((float) $reservation->dp_amount > 0)<div class="money">Rp {{ number_format($reservation->dp_amount,0,',','.') }}</div><div class="cell-sub">{{ \App\Models\Reservation::DP_METHODS[$reservation->dp_method] ?? strtoupper((string) $reservation->dp_method) }}{{ $reservation->dp_provider ? ' · '.$reservation->dp_provider : '' }}</div>
                @if($reservation->dp_refunded)<span class="badge gray">Dikembalikan</span>@elseif(in_array($reservation->status, ['cancelled','no_show'], true))<span class="badge red">DP hangus</span>@elseif($reservation->status === 'completed' && (float) $reservation->dp_amount > (float) $reservation->dp_used)<div class="cell-sub">Sisa DP Rp {{ number_format($reservation->dp_amount - $reservation->dp_used,0,',','.') }} belum terpakai</div>@endif
                @else<span class="cell-sub">Tanpa DP</span>@endif</td>
            <td><span class="badge {{ $statusBadge[$reservation->status] ?? 'gray' }}">{{ $reservation->statusLabel() }}</span>
                @if($reservation->transaction)<div class="cell-sub">{{ $reservation->transaction->invoice_no }}{{ $reservation->transaction->status === 'pending' ? ' · open bill' : '' }}</div>@endif
                @if($reservation->cancel_reason)<div class="cell-sub">{{ $reservation->cancel_reason }}</div>@endif</td>
            <td><div class="actions">
                @if($reservation->isOpen())
                    @if($reservation->status === 'booked')<form method="POST" action="{{ route('reservations.status',$reservation) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="arrived"><button class="btn btn-soft btn-sm"><i class="bi bi-person-check"></i> Tamu datang</button></form>@endif
                    <button type="button" class="btn btn-outline btn-sm edit-reservation" data-url="{{ route('reservations.update',$reservation) }}" data-reservation="{{ json_encode(['customer_name'=>$reservation->customer_name,'phone'=>$reservation->phone,'reserved_date'=>$reservation->reserved_at->toDateString(),'reserved_time'=>$reservation->reserved_at->format('H:i'),'guests'=>$reservation->guests,'table_number'=>$reservation->table_number,'notes'=>$reservation->notes,'dp_amount'=>(float)$reservation->dp_amount,'dp_method'=>$reservation->dp_method,'dp_provider'=>$reservation->dp_provider]) }}"><i class="bi bi-pencil"></i> Ubah</button>
                    @unless($reservation->transaction_id)<button type="button" class="btn btn-danger btn-sm cancel-reservation" data-url="{{ route('reservations.status',$reservation) }}" data-code="{{ $reservation->code }}" data-dp="{{ (float) $reservation->dp_amount }}" title="Batal / tidak datang"><i class="bi bi-x-lg"></i></button>@endunless
                @endif
            </div></td>
        </tr>
    @empty
        <tr><td colspan="6"><div class="cart-empty">Belum ada reservasi pada tanggal ini.</div></td></tr>
    @endforelse
    </tbody></table></div>
    @if($upcoming->isNotEmpty())
        <div class="hint" style="margin-top:14px"><b>Reservasi berikutnya:</b> @foreach($upcoming as $day)<a href="{{ route('reservations', ['date' => $day->day]) }}" class="badge gray" style="margin:2px">{{ \Carbon\Carbon::parse($day->day)->translatedFormat('D, d M') }} · {{ $day->total }}</a>@endforeach</div>
    @endif
</section>
@endsection

@push('modals')
<div class="modal" id="reservation-modal"><div class="modal-card"><div class="modal-head"><div><h2 id="reservation-title">Reservasi baru</h2><div class="hint">DP bersifat opsional. DP tunai ikut dihitung pada tutup kasir hari diterima.</div></div><button class="modal-close" onclick="closeModal('reservation-modal')">×</button></div>
<form method="POST" action="{{ route('reservations.store') }}" id="reservation-form" class="form-grid">@csrf<input type="hidden" name="_method" value="POST">
    <div class="field"><label>Nama pemesan</label><input name="customer_name" maxlength="120" required></div>
    <div class="field"><label>No. HP / WhatsApp</label><input name="phone" maxlength="30" inputmode="tel" placeholder="08…"></div>
    <div class="field"><label>Tanggal</label><input type="date" name="reserved_date" value="{{ $date->toDateString() }}" required></div>
    <div class="field"><label>Jam datang</label><input type="time" name="reserved_time" value="19:00" required></div>
    <div class="field"><label>Jumlah orang</label><input type="number" name="guests" min="1" max="1000" value="2" required></div>
    <div class="field"><label>Meja / area</label><input name="table_number" maxlength="20" placeholder="Contoh: A-07 / Lesehan"></div>
    <div class="field full"><label>Catatan</label><textarea name="notes" rows="2" maxlength="500" placeholder="Acara, permintaan khusus, menu pesanan awal…"></textarea></div>
    <div class="field"><label>DP diterima</label><input type="text" name="dp_amount" data-money-input data-min="0" inputmode="numeric" value="0"></div>
    <div class="field"><label>Cara bayar DP</label><select name="dp_method"><option value="">Tanpa DP</option>@foreach(\App\Models\Reservation::DP_METHODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
    <div class="field full" id="dp-provider-field" hidden><label>Bank / provider penerima DP</label><input name="dp_provider" maxlength="80" list="dp-providers" placeholder="Contoh: BCA / QRIS BRI"><datalist id="dp-providers"><option>BCA</option><option>BRI</option><option>BNI</option><option>Mandiri</option><option>QRIS BCA</option><option>QRIS BRI</option><option>QRIS BNI</option><option>QRIS Mandiri</option></datalist></div>
    <div class="field full"><button class="btn btn-primary" id="reservation-submit">Simpan reservasi</button></div>
</form></div></div>

<div class="modal" id="cancel-reservation-modal"><div class="modal-card" style="max-width:460px"><div class="modal-head"><div><h2>Batalkan reservasi <span id="cancel-reservation-code"></span></h2><div class="hint">Reservasi tetap tersimpan sebagai riwayat.</div></div><button class="modal-close" onclick="closeModal('cancel-reservation-modal')">×</button></div>
<form method="POST" id="cancel-reservation-form" class="form-grid">@csrf @method('PATCH')
    <div class="field full"><label>Status</label><select name="status"><option value="cancelled">Batal (dibatalkan tamu / warung)</option><option value="no_show">Tidak datang</option></select></div>
    <div class="field full"><label>Alasan / catatan</label><input name="reason" maxlength="255" placeholder="Wajib untuk pembatalan"></div>
    <label class="inventory-note amber" id="refund-dp-field"><input type="checkbox" name="refund_dp" value="1" style="width:auto;min-height:auto"><span><b>DP dikembalikan ke tamu</b><br>Bila dicentang dan DP tunai, kas seharusnya hari ini berkurang. Tanpa centang, DP dianggap hangus.</span></label>
    <div class="field full"><button class="btn btn-danger">Simpan pembatalan</button></div>
</form></div></div>
@endpush

@push('scripts')<script>
const reservationForm=document.getElementById('reservation-form');
function toggleDpProvider(){const method=reservationForm.elements.dp_method.value;document.getElementById('dp-provider-field').hidden=!['qris','transfer','debit'].includes(method)}
reservationForm.elements.dp_method.addEventListener('change',toggleDpProvider);
function openReservationForm(url=null,data=null){reservationForm.reset();reservationForm.action=url||'{{ route('reservations.store') }}';reservationForm.elements._method.value=url?'PUT':'POST';document.getElementById('reservation-title').textContent=url?'Ubah reservasi':'Reservasi baru';document.getElementById('reservation-submit').textContent=url?'Simpan perubahan':'Simpan reservasi';if(data)Object.entries(data).forEach(([key,value])=>{const input=reservationForm.elements[key];if(input&&key!=='dp_amount')input.value=value??''});setMoneyInputValue(reservationForm.elements.dp_amount,Number(data?.dp_amount||0));toggleDpProvider();openModal('reservation-modal')}
document.querySelectorAll('.edit-reservation').forEach(button=>button.addEventListener('click',()=>openReservationForm(button.dataset.url,JSON.parse(button.dataset.reservation))));
document.querySelectorAll('.cancel-reservation').forEach(button=>button.addEventListener('click',()=>{const form=document.getElementById('cancel-reservation-form');form.reset();form.action=button.dataset.url;document.getElementById('cancel-reservation-code').textContent=button.dataset.code;document.getElementById('refund-dp-field').hidden=!(Number(button.dataset.dp)>0);openModal('cancel-reservation-modal')}));
@if($errors->any() && old('customer_name') !== null && old('_method', 'POST') === 'POST')document.addEventListener('DOMContentLoaded',()=>openReservationForm(null,@json(collect(old())->except(['_token', '_method']))));@endif
</script>@endpush
