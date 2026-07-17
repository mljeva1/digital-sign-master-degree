{{-- Session success/error + validation errors, shown consistently across pages. --}}
@if (session('error'))
    <div class="mb-6 flex items-start gap-3 rounded-2xl border border-red-300/20 bg-red-300/10 px-4 py-3 text-sm text-red-100" role="alert">
        <svg viewBox="0 0 20 20" class="mt-0.5 h-4 w-4 shrink-0 text-red-300" aria-hidden="true">
            <path fill="currentColor" d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm-1 4a1 1 0 1 1 2 0v4a1 1 0 1 1-2 0V6Zm1 9.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5Z"/>
        </svg>
        <span>{{ session('error') }}</span>
    </div>
@endif

@if (session('success'))
    <div class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100" role="status" aria-live="polite">
        <svg viewBox="0 0 20 20" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-300" aria-hidden="true">
            <path fill="currentColor" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0l-3.5-3.5a1 1 0 1 1 1.4-1.4l2.8 2.79 6.8-6.79a1 1 0 0 1 1.4 0Z"/>
        </svg>
        <span>{{ session('success') }}</span>
    </div>
@endif

@if ($errors->any())
    <div class="mb-6 rounded-2xl border border-red-300/20 bg-red-300/10 px-4 py-3 text-sm text-red-100" role="alert">
        <p class="font-semibold">Provjeri unesene podatke:</p>
        <ul class="mt-2 list-inside list-disc space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
