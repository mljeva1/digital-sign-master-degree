<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Draftovi ugovora | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto max-w-6xl px-5 py-8">
        <header class="flex flex-col gap-4 border-b border-white/10 pb-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold tracking-[0.28em] text-cyan-200">DSMD</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Draftovi ugovora</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Spremljeni poslovni zapisi ugovora, odvojeni od uploadanih dokumenata.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('dashboard') }}" class="rounded-full border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                    Dashboard
                </a>
                <a href="{{ route('contracts.create') }}" class="rounded-full bg-cyan-300 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                    Novi ugovor
                </a>
            </div>
        </header>

        <main class="mt-8">
            @if (session('success'))
                <div class="mb-6 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">
                    {{ session('success') }}
                </div>
            @endif

            <div class="grid gap-5 md:grid-cols-2">
                @forelse ($contracts as $contract)
                    @php
                        $snapshot = $contract->filled_data_snapshot ?? [];
                        $buyer = data_get($snapshot, 'buyer_name');
                        $vehicle = trim(implode(' ', array_filter([
                            data_get($snapshot, 'vehicle_brand'),
                            data_get($snapshot, 'vehicle_model'),
                        ])));
                        $hasDraftPdfFile = $contract->draftPdfFile
                            && filled($contract->draftPdfFile->storage_path);
                    @endphp

                    <article class="rounded-[2rem] border border-white/10 bg-white/[0.04] p-6 shadow-2xl shadow-slate-950/30">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">
                                    Ugovor #{{ $contract->id }}
                                </p>
                                <h2 class="mt-2 text-xl font-semibold text-white">
                                    {{ $buyer ?: 'Kupac nije unesen' }}
                                </h2>
                                <p class="mt-1 text-sm text-slate-400">
                                    {{ $vehicle ?: 'Vozilo nije uneseno' }}
                                </p>
                            </div>

                            <span class="rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-xs font-semibold uppercase text-cyan-100">
                                {{ $contract->status }}
                            </span>
                        </div>

                        <dl class="mt-6 grid gap-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-500">Zadnja izmjena</dt>
                                <dd class="text-right text-slate-200">
                                    {{ $contract->updated_at?->format('d.m.Y. H:i') ?? 'N/A' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-500">Stanje uređivanja</dt>
                                <dd class="text-right text-slate-200">
                                    {{ $contract->canBeEdited() ? 'Otvoren draft' : 'Nije moguće uređivati' }}
                                </dd>
                            </div>
                            @if ($hasDraftPdfFile)
                                <div class="rounded-2xl border border-amber-300/20 bg-amber-300/10 px-4 py-3">
                                    <dt class="font-semibold text-amber-100">Probni PDF generiran</dt>
                                    <dd class="mt-1 text-xs text-amber-100/80">
                                        {{ $contract->draftPdfFile?->updated_at?->format('d.m.Y. H:i') ?? 'N/A' }}
                                        @if (filled($contract->draft_pdf_sha256))
                                            · SHA-256: {{ Str::limit($contract->draft_pdf_sha256, 12, '') }}
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        </dl>

                        @if ($contract->canBeEdited())
                            <div class="mt-6 flex flex-wrap gap-3">
                                <a href="{{ route('contracts.builder.edit', $contract) }}" class="inline-flex rounded-full bg-emerald-300 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-emerald-200">
                                    Nastavi uređivanje
                                </a>

                                <form method="POST" action="{{ route('contracts.draft-pdf.store', $contract) }}">
                                    @csrf
                                    <button type="submit" class="rounded-full border border-amber-300/20 bg-amber-300/10 px-5 py-2.5 text-sm font-bold text-amber-100 transition hover:bg-amber-300/15">
                                        Generiraj probni PDF
                                    </button>
                                </form>

                                @if ($hasDraftPdfFile)
                                    <a href="{{ route('contracts.draft-pdf.show', $contract) }}" target="_blank" rel="noopener" class="inline-flex rounded-full border border-cyan-300/20 bg-cyan-300/10 px-5 py-2.5 text-sm font-bold text-cyan-100 transition hover:bg-cyan-300/15">
                                        Otvori probni PDF
                                    </a>
                                @endif

                                @if ($hasDraftPdfFile && filled($contract->draft_pdf_sha256))
                                    <a href="{{ route('contracts.draft-pdf.verify', $contract) }}" class="inline-flex rounded-full border border-violet-300/20 bg-violet-300/10 px-5 py-2.5 text-sm font-bold text-violet-100 transition hover:bg-violet-300/15">
                                        Provjeri SHA-256
                                    </a>
                                @endif

                                <form method="POST" action="{{ route('contracts.archive', $contract) }}" onsubmit="return confirm('Arhivirati ovaj draft ugovora?')">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="rounded-full border border-red-300/20 bg-red-300/10 px-5 py-2.5 text-sm font-bold text-red-100 transition hover:bg-red-300/15">
                                        Arhiviraj
                                    </button>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="md:col-span-2 rounded-[2rem] border border-dashed border-white/15 bg-white/[0.03] px-6 py-14 text-center">
                        <p class="text-lg font-semibold text-white">Nemate spremljenih draftova ugovora.</p>
                        <a href="{{ route('contracts.create') }}" class="mt-5 inline-flex rounded-full bg-cyan-300 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                            Novi ugovor
                        </a>
                    </div>
                @endforelse
            </div>
        </main>
    </div>
</body>
</html>
