@props(['padding' => 'p-6'])

<div {{ $attributes->class(['rounded-3xl border border-white/10 bg-white/[0.035] shadow-2xl shadow-slate-950/20', $padding]) }}>
    {{ $slot }}
</div>
