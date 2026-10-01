{{-- Modal Import Excel: panduan kolom diambil dari App\Support\ImportTemplate agar sama dengan file template. --}}
@php($importFormats = \App\Support\ImportTemplate::FORMATS)
@php($statusBadges = ['wajib' => 'import-required', 'menu' => 'amber', 'opsional' => 'gray'])
<div class="modal" id="import-modal"><div class="modal-card import-card">
    <div class="modal-head"><div><h2>Import produk dari Excel</h2><div class="hint">Tambah atau perbarui menu, bahan baku, dan stok sekaligus.</div></div><button class="modal-close" type="button" onclick="closeModal('import-modal')" aria-label="Tutup">×</button></div>
    <form method="POST" action="{{ route('products.import') }}" enctype="multipart/form-data" class="import-form">@csrf
        <div class="import-step"><span class="import-step-no">1</span><div><b>Pilih format &amp; siapkan file</b><small>Klik format untuk melihat kolom yang harus diisi, atau unduh template kosongnya.</small></div></div>
        <div class="import-tabs" role="tablist">
            @foreach($importFormats as $key => $format)
                <button type="button" role="tab" class="import-tab {{ $loop->first ? 'active' : '' }}" data-import-tab="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"><b>{{ $format['label'] }}</b><small>{{ $format['badge'] }}</small></button>
            @endforeach
        </div>
        @foreach($importFormats as $key => $format)
            <div class="import-panel" role="tabpanel" data-import-panel="{{ $key }}" @unless($loop->first) hidden @endunless>
                <p class="import-summary">{{ $format['summary'] }}</p>
                <div class="actions">
                    <a class="btn btn-soft btn-sm" href="{{ route('products.import-template', $key) }}"><i class="bi bi-file-earmark-arrow-down"></i> Download template</a>
                    @if($key === 'standard')<a class="btn btn-outline btn-sm" href="{{ route('products.export') }}"><i class="bi bi-table"></i> Download data produk saat ini</a>@endif
                </div>
                @foreach(\App\Support\ImportTemplate::guideSheets($key) as $sheet)
                    @php($requiredColumns = collect($sheet['columns'])->where(1, '!=', 'opsional')->map(fn ($column) => $column[0].($column[1] === 'menu' ? ' (menu)' : '')))
                    {{-- Kolom wajib langsung terlihat; tabel lengkap dibuka bila perlu agar area upload tidak terdorong jauh. --}}
                    <details class="import-columns">
                        <summary><span class="import-columns-title"><i class="bi bi-chevron-right"></i> {{ $sheet['title'] }}</span><span class="import-columns-count">Lihat {{ count($sheet['columns']) }} kolom</span><span class="import-required-list">Wajib: @foreach($requiredColumns as $column)<code>{{ $column }}</code>@endforeach</span></summary>
                        <div class="table-wrap"><table class="import-table"><thead><tr><th>Kolom</th><th>Status</th><th>Keterangan</th><th class="import-example">Contoh</th></tr></thead><tbody>
                            @foreach($sheet['columns'] as [$column, $status, $description, $example])
                                <tr><td><code>{{ $column }}</code></td><td><span class="badge {{ $statusBadges[$status] }}">{{ \App\Support\ImportTemplate::STATUS_LABELS[$status] }}</span></td><td>{{ $description }}</td><td class="import-example">{{ $example !== '' ? $example : '—' }}</td></tr>
                            @endforeach
                        </tbody></table></div>
                    </details>
                @endforeach
                <ul class="import-rules">@foreach($format['rules'] as $rule)<li>{{ $rule }}</li>@endforeach</ul>
            </div>
        @endforeach
        <div class="import-step"><span class="import-step-no">2</span><div><b>Upload file</b><small>Stok dari kolom STOK / Stok Awal masuk ke cabang <strong>{{ $activeStore->name }}</strong>. Ganti cabang di kanan atas bila perlu.</small></div></div>
        <input type="file" name="file" accept=".xlsx,.xls,.csv" required data-file-hint="Excel .xlsx / .xls atau .csv" data-max-mb="5">
        <button class="btn btn-primary import-submit"><i class="bi bi-upload"></i> Import &amp; perbarui</button>
    </form>
</div></div>
