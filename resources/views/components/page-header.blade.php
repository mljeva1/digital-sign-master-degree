@props([
    'title',
    'subtitle' => null,
    'eyebrow' => 'DSMD',
    'back' => null,
    'backLabel' => 'Natrag',
])

<header class="flex flex-col gap-4 border-b border-white/10 pb-6 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-400 transition hover:text-cyan-200">
                <svg viewBox="0 0 20 20" class="h-4 w-4" aria-hidden="true">
                    <path fill="currentColor" d="M12.7 4.3a1 1 0 0 1 0 1.4L8.42 10l4.3 4.3a1 1 0 1 1-1.42 1.4l-5-5a1 1 0 0 1 0-1.4l5-5a1 1 0 0 1 1.4 0Z"/>
                </svg>
                {{ $backLabel }}
            </a>
        @else
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-200">{{ $eyebrow }}</p>
        @endif

        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">{{ $title }}</h1>

        @if ($subtitle)
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
