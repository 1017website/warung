@props(['icon' => null, 'color' => null, 'size' => 'md'])
@php($icon = $icon ?: 'bi-tag')
<span {{ $attributes->class(['menu-icon', 'menu-icon-'.$size]) }} @if($color) style="--menu-icon-color: {{ $color }}" @endif aria-hidden="true">
    @if(\App\Support\MenuIcon::isBootstrap($icon))<i class="bi {{ $icon }}"></i>@else<span class="menu-icon-emoji">{{ $icon }}</span>@endif
</span>
