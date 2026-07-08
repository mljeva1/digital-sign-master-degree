<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ugovori | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto max-w-6xl px-5 py-8">
        <header class="flex flex-col gap-4 border-b border-white/10 pb-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold tracking-[0.28em] text-cyan-200">DSMD</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Ugovori</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Spremljeni poslovni zapisi ugovora, odvojeni od uploadanih dokumenata.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('dashboard') }}" class="inline-flex h-10 items-center rounded-xl border border-white/10 bg-white/[0.03] px-4 text-sm font-medium text-slate-300 transition hover:border-white/20 hover:bg-white/[0.07] hover:text-white">
                    Dashboard
                </a>
                <a href="{{ route('contracts.create') }}" class="inline-flex h-10 items-center rounded-xl border border-cyan-300/30 bg-cyan-300/10 px-4 text-sm font-semibold text-cyan-100 transition hover:border-cyan-200/50 hover:bg-cyan-300/15">
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
                        $hasFinalPdfFile = $contract->finalPdfFile
                            && filled($contract->finalPdfFile->storage_path);
                    @endphp

                    <article class="rounded-[2rem] border border-white/10 bg-white/[0.035] p-6 shadow-2xl shadow-slate-950/20 transition hover:border-white/15">
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
                            @if ($hasFinalPdfFile)
                                <div class="rounded-2xl border border-teal-300/20 bg-teal-300/10 px-4 py-3">
                                    <dt class="font-semibold text-teal-100">Finalni PDF generiran</dt>
                                    <dd class="mt-1 text-xs text-teal-100/80">
                                        {{ $contract->finalPdfFile?->updated_at?->format('d.m.Y. H:i') ?? 'N/A' }}
                                        @if (filled($contract->final_pdf_sha256))
                                            · SHA-256: {{ Str::limit($contract->final_pdf_sha256, 12, '') }}
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        </dl>

                        @if ($contract->canBeEdited())
                            <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-white/10 pt-5">
                                <a href="{{ route('contracts.builder.edit', $contract) }}" class="inline-flex h-9 items-center rounded-lg border border-emerald-300/25 bg-emerald-300/10 px-3.5 text-xs font-semibold text-emerald-100 transition hover:border-emerald-300/45 hover:bg-emerald-300/15 focus:outline-none focus:ring-2 focus:ring-emerald-300/20">
                                    Nastavi uređivanje
                                </a>

                                <form method="POST" action="{{ route('contracts.draft-pdf.store', $contract) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex h-9 items-center rounded-lg border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-amber-300/25 hover:bg-amber-300/[0.07] hover:text-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-300/15">
                                        Generiraj probni PDF
                                    </button>
                                </form>

                                @if ($hasDraftPdfFile)
                                    <a href="{{ route('contracts.draft-pdf.show', $contract) }}" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-lg border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-cyan-300/25 hover:bg-cyan-300/[0.07] hover:text-cyan-100 focus:outline-none focus:ring-2 focus:ring-cyan-300/15">
                                        Otvori probni PDF
                                    </a>
                                @endif

                                @if ($hasDraftPdfFile && filled($contract->draft_pdf_sha256))
                                    <a href="{{ route('contracts.draft-pdf.verify', $contract) }}" class="inline-flex h-9 items-center rounded-lg border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-violet-300/25 hover:bg-violet-300/[0.07] hover:text-violet-100 focus:outline-none focus:ring-2 focus:ring-violet-300/15">
                                        Provjeri SHA-256
                                    </a>
                                @endif

                                <form method="POST" action="{{ route('contracts.archive', $contract) }}" class="sm:ml-auto" onsubmit="return confirm('Arhivirati ovaj draft ugovora?')">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="inline-flex h-9 items-center rounded-lg border border-transparent px-3 text-xs font-medium text-slate-500 transition hover:border-red-300/15 hover:bg-red-300/[0.06] hover:text-red-200 focus:outline-none focus:ring-2 focus:ring-red-300/15">
                                        Arhiviraj
                                    </button>
                                </form>
                            </div>
                        @endif

                        @if ($contract->isFinalized())
                            <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-white/10 pt-5">
                                <form method="POST" action="{{ route('contracts.final-pdf.store', $contract) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex h-9 items-center rounded-lg border border-teal-300/25 bg-teal-300/10 px-3.5 text-xs font-semibold text-teal-100 transition hover:border-teal-300/45 hover:bg-teal-300/15 focus:outline-none focus:ring-2 focus:ring-teal-300/20">
                                        Generiraj finalni PDF
                                    </button>
                                </form>

                                @if ($hasFinalPdfFile)
                                    <a href="{{ route('contracts.final-pdf.show', $contract) }}" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-lg border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-cyan-300/25 hover:bg-cyan-300/[0.07] hover:text-cyan-100">
                                        Prikaži finalni PDF
                                    </a>

                                    <a href="{{ route('contracts.final-pdf.verify', $contract) }}" class="inline-flex h-9 items-center rounded-lg border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-violet-300/25 hover:bg-violet-300/[0.07] hover:text-violet-100">
                                        Provjeri finalni PDF
                                    </a>
                                @endif

                                <form method="POST" action="{{ route('contracts.public-verification.enable', $contract) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex h-9 items-center rounded-lg border border-cyan-300/25 bg-cyan-300/10 px-3.5 text-xs font-semibold text-cyan-100 transition hover:border-cyan-300/45 hover:bg-cyan-300/15">
                                        Omogući javnu provjeru
                                    </button>
                                </form>

                                @if ($contract->public_verification_token && $contract->public_verification_enabled_at && ! $contract->public_verification_revoked_at)
                                    <a href="{{ route('public.contracts.verify.show', $contract->public_verification_token) }}" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-lg border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-cyan-300/25 hover:text-cyan-100">
                                        Otvori javnu provjeru
                                    </a>
                                @endif
                            </div>
                        @endif

                        <div class="{{ $contract->canBeEdited() ? 'mt-3' : 'mt-6 border-t border-white/10 pt-5' }}">
                            <a href="{{ route('contracts.audit.index', $contract) }}" class="inline-flex h-9 items-center rounded-lg border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-cyan-300/25 hover:bg-cyan-300/[0.07] hover:text-cyan-100 focus:outline-none focus:ring-2 focus:ring-cyan-300/15">
                                Audit trag
                            </a>
                        </div>
                    </article>
                @empty
                    <div class="md:col-span-2 rounded-[2rem] border border-dashed border-white/15 bg-white/[0.03] px-6 py-14 text-center">
                        <p class="text-lg font-semibold text-white">Nemate spremljenih ugovora.</p>
                        <a href="{{ route('contracts.create') }}" class="mt-5 inline-flex h-10 items-center rounded-xl border border-cyan-300/30 bg-cyan-300/10 px-4 text-sm font-semibold text-cyan-100 transition hover:border-cyan-200/50 hover:bg-cyan-300/15">
                            Novi ugovor
                        </a>
                    </div>
                @endforelse
            </div>
        </main>
    </div>
</body>
</html>
