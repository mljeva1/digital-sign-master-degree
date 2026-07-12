@props([
    'variant' => 'secondary',
    'href' => null,
    'type' => 'button',
    'size' => 'md',
])

@php
    $variants = [
        'primary' => 'bg-cyan-300 text-slate-950 hover:bg-cyan-200 font-bold',
        'secondary' => 'border border-white/10 bg-white/5 text-white hover:bg-white/10 font-semibold',
        'ghost' => 'text-slate-300 hover:bg-white/5 hover:text-white font-medium',
        'danger' => 'border border-red-300/25 bg-red-300/10 text-red-100 hover:border-red-300/45 hover:bg-red-300/15 font-semibold',
        'success' => 'border border-emerald-300/25 bg-emerald-300/10 text-emerald-100 hover:border-emerald-300/45 hover:bg-emerald-300/15 font-semibold',
    ];
    $sizes = [
        'md' => 'min-h-[44px] px-5 text-sm',
        'sm' => 'min-h-[44px] px-3.5 text-xs',
    ];
    $classes = 'inline-flex items-center justify-center gap-2 rounded-xl transition '
        .($variants[$variant] ?? $variants['secondary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
