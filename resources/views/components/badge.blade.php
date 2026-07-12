@props([
    'tone' => 'slate',
])

@php
    $tones = [
        'cyan' => 'border-cyan-300/25 bg-cyan-300/10 text-cyan-100',
        'emerald' => 'border-emerald-300/25 bg-emerald-300/10 text-emerald-100',
        'amber' => 'border-amber-300/25 bg-amber-300/10 text-amber-100',
        'red' => 'border-red-300/25 bg-red-300/10 text-red-100',
        'teal' => 'border-teal-300/25 bg-teal-300/10 text-teal-100',
        'slate' => 'border-white/15 bg-white/5 text-slate-200',
    ];
    $classes = $tones[$tone] ?? $tones['slate'];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold', $classes]) }}>
    {{ $slot }}
</span>
