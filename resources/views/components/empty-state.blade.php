@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class(['rounded-3xl border border-dashed border-white/15 bg-white/[0.03] px-6 py-14 text-center']) }}>
    <p class="text-lg font-semibold text-white">{{ $title }}</p>

    @if ($description)
        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-400">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="mt-6 flex justify-center">
            {{ $action }}
        </div>
    @endisset
</div>
