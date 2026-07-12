@props([
    'title' => 'Digital Sign Master Degree',
    'active' => '',
    'maxWidth' => 'max-w-6xl',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style>
        /* Design-system baseline: readable mobile inputs, visible focus,
           reduced-motion respect. Kept intentionally small — Tailwind
           utility classes carry the rest. */
        html { -webkit-text-size-adjust: 100%; }
        input, select, textarea { font-size: 16px; }
        :where(a, button, input, select, textarea, [tabindex]):focus-visible {
            outline: 2px solid #67e8f9;
            outline-offset: 2px;
            border-radius: 0.5rem;
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.001ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-cyan-400/30 selection:text-cyan-50">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[200] focus:rounded-xl focus:bg-cyan-300 focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-slate-950">
        Preskoči na sadržaj
    </a>

    <x-nav :active="$active" />

    <main id="main-content" class="mx-auto w-full {{ $maxWidth }} px-5 py-8 sm:px-6 lg:px-8">
        {{ $slot }}
    </main>
</body>
</html>
