{{-- Pagination aplikasi: tanpa kelas Tailwind (tidak ikut mode gelap OS) dan berbahasa Indonesia. --}}
@if ($paginator->hasPages())
<nav class="pager" role="navigation" aria-label="Navigasi halaman">
    <p class="pager-summary">
        Menampilkan <b>{{ $paginator->firstItem() }}</b>–<b>{{ $paginator->lastItem() }}</b> dari <b>{{ number_format($paginator->total(), 0, ',', '.') }}</b> data
        <span class="pager-page">· Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</span>
    </p>
    <div class="pager-links">
        @if ($paginator->onFirstPage())
            <span aria-disabled="true"><span aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i></span></span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i></a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span aria-disabled="true" class="pager-num"><span>…</span></span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page"><span>{{ $page }}</span></span>
                    @else
                        <a href="{{ $url }}" class="pager-num" aria-label="Halaman {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Berikutnya"><i class="bi bi-chevron-right"></i></a>
        @else
            <span aria-disabled="true"><span aria-label="Berikutnya"><i class="bi bi-chevron-right"></i></span></span>
        @endif
    </div>
</nav>
@endif
