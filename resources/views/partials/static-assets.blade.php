@php
    $assetVersion = static function (string $path): string {
        $absolutePath = public_path($path);

        return is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';
    };
@endphp
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ $assetVersion('css/app.css') }}">
<link rel="stylesheet" href="{{ asset('css/overrides.css') }}?v={{ $assetVersion('css/overrides.css') }}">
<script defer src="{{ asset('js/app.js') }}?v={{ $assetVersion('js/app.js') }}"></script>
<script defer src="{{ asset('js/overrides.js') }}?v={{ $assetVersion('js/overrides.js') }}"></script>
