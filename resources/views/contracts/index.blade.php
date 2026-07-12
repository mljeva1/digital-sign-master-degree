@php
    $statusTones = [
        \App\Models\Contract::STATUS_DRAFT => 'cyan',
        \App\Models\Contract::STATUS_FINALIZED => 'teal',
        \App\Models\Contract::STATUS_FULLY_SIGNED => 'emerald',
        \App\Models\Contract::STATUS_ARCHIVED => 'slate',
    ];
@endphp

<x-app-layout title="Ugovori" active="contracts">
    <x-page-header
        title="Ugovori"
        subtitle="Spremljeni poslovni zapisi ugovora, odvojeni od uploadanih dokumenata."
    >
        <x-slot:actions>
            <x-action href="{{ route('contracts.create') }}" variant="primary">Novi ugovor</x-action>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-8">
        <x-flash />

        <div class="grid gap-5 md:grid-cols-2">
            @forelse ($contracts as $contract)
                @php
                    $snapshot = $contract->filled_data_snapshot ?? [];
                    $buyer = data_get($snapshot, 'buyer_name');
                    $vehicle = trim(implode(' ', array_filter([
                        data_get($snapshot, 'vehicle_brand'),
                        data_get($snapshot, 'vehicle_model'),
                    ])));
                    $hasDraftPdfFile = $contract->draftPdfFile && filled($contract->draftPdfFile->storage_path);
                    $hasFinalPdfFile = $contract->finalPdfFile && filled($contract->finalPdfFile->storage_path);
                    $publicVerifyActive = $contract->public_verification_token
                        && $contract->public_verification_enabled_at
                        && ! $contract->public_verification_revoked_at;
                @endphp

                <x-card class="flex flex-col p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">
                                Ugovor #{{ $contract->id }}
                            </p>
                            <h2 class="mt-2 truncate text-xl font-semibold text-white">
                                {{ $buyer ?: 'Kupac nije unesen' }}
                            </h2>
                            <p class="mt-1 truncate text-sm text-slate-400">
                                {{ $vehicle ?: 'Vozilo nije uneseno' }}
                            </p>
                        </div>

                        <x-badge :tone="$statusTones[$contract->status] ?? 'slate'" class="uppercase shrink-0">
                            {{ $contract->status }}
                        </x-badge>
                    </div>

                    <dl class="mt-6 grid gap-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Zadnja izmjena</dt>
                            <dd class="text-right text-slate-200">{{ $contract->updated_at?->format('d.m.Y. H:i') ?? 'N/A' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Stanje uređivanja</dt>
                            <dd class="text-right text-slate-200">{{ $contract->canBeEdited() ? 'Otvoren draft' : 'Nije moguće uređivati' }}</dd>
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

                    {{-- Actions: one clear primary per status, read-only secondary actions in an overflow,
                         state-changing actions kept visually separate. --}}
                    <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-white/10 pt-5">
                        @if ($contract->canBeEdited())
                            <x-action href="{{ route('contracts.builder.edit', $contract) }}" variant="success" size="sm">
                                Nastavi uređivanje
                            </x-action>

                            <details class="group relative">
                                <summary class="inline-flex min-h-[44px] cursor-pointer list-none items-center gap-1.5 rounded-xl border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-white/20 hover:text-white [&::-webkit-details-marker]:hidden">
                                    Više
                                    <svg viewBox="0 0 20 20" class="h-3.5 w-3.5 transition group-open:rotate-180" aria-hidden="true"><path fill="currentColor" d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.58l3.3-3.3a1 1 0 1 1 1.4 1.42l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.42Z"/></svg>
                                </summary>
                                <div class="absolute left-0 z-20 mt-2 flex w-56 flex-col gap-1 rounded-2xl border border-white/10 bg-slate-900 p-2 shadow-2xl shadow-black/50">
                                    <form method="POST" action="{{ route('contracts.draft-pdf.store', $contract) }}">
                                        @csrf
                                        <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-xs font-medium text-slate-300 transition hover:bg-amber-300/10 hover:text-amber-100">Generiraj probni PDF</button>
                                    </form>
                                    @if ($hasDraftPdfFile)
                                        <a href="{{ route('contracts.draft-pdf.show', $contract) }}" target="_blank" rel="noopener" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-300 transition hover:bg-cyan-300/10 hover:text-cyan-100">Otvori probni PDF</a>
                                    @endif
                                    @if ($hasDraftPdfFile && filled($contract->draft_pdf_sha256))
                                        <a href="{{ route('contracts.draft-pdf.verify', $contract) }}" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-300 transition hover:bg-violet-300/10 hover:text-violet-100">Provjeri SHA-256</a>
                                    @endif
                                    <a href="{{ route('contracts.audit.index', $contract) }}" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-300 transition hover:bg-cyan-300/10 hover:text-cyan-100">Audit trag</a>
                                </div>
                            </details>

                            <form method="POST" action="{{ route('contracts.archive', $contract) }}" class="sm:ml-auto" onsubmit="return confirm('Arhivirati ovaj draft ugovora?')">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="inline-flex min-h-[44px] items-center rounded-xl border border-transparent px-3 text-xs font-medium text-slate-500 transition hover:border-red-300/20 hover:bg-red-300/[0.07] hover:text-red-200">Arhiviraj</button>
                            </form>
                        @elseif ($contract->isFinalized())
                            <form method="POST" action="{{ route('contracts.final-pdf.store', $contract) }}">
                                @csrf
                                <button type="submit" class="inline-flex min-h-[44px] items-center rounded-xl border border-teal-300/25 bg-teal-300/10 px-3.5 text-xs font-semibold text-teal-100 transition hover:border-teal-300/45 hover:bg-teal-300/15">Generiraj finalni PDF</button>
                            </form>

                            <details class="group relative">
                                <summary class="inline-flex min-h-[44px] cursor-pointer list-none items-center gap-1.5 rounded-xl border border-white/10 bg-white/[0.025] px-3.5 text-xs font-medium text-slate-300 transition hover:border-white/20 hover:text-white [&::-webkit-details-marker]:hidden">
                                    Više
                                    <svg viewBox="0 0 20 20" class="h-3.5 w-3.5 transition group-open:rotate-180" aria-hidden="true"><path fill="currentColor" d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.58l3.3-3.3a1 1 0 1 1 1.4 1.42l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.42Z"/></svg>
                                </summary>
                                <div class="absolute left-0 z-20 mt-2 flex w-56 flex-col gap-1 rounded-2xl border border-white/10 bg-slate-900 p-2 shadow-2xl shadow-black/50">
                                    @if ($hasFinalPdfFile)
                                        <a href="{{ route('contracts.final-pdf.show', $contract) }}" target="_blank" rel="noopener" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-300 transition hover:bg-cyan-300/10 hover:text-cyan-100">Prikaži finalni PDF</a>
                                        <a href="{{ route('contracts.final-pdf.verify', $contract) }}" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-300 transition hover:bg-violet-300/10 hover:text-violet-100">Provjeri finalni PDF</a>
                                    @endif
                                    @if ($publicVerifyActive)
                                        <a href="{{ route('public.contracts.verify.show', $contract->public_verification_token) }}" target="_blank" rel="noopener" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-300 transition hover:bg-cyan-300/10 hover:text-cyan-100">Otvori javnu provjeru</a>
                                    @endif
                                    <a href="{{ route('contracts.audit.index', $contract) }}" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-300 transition hover:bg-cyan-300/10 hover:text-cyan-100">Audit trag</a>
                                </div>
                            </details>

                            <form method="POST" action="{{ route('contracts.public-verification.enable', $contract) }}" class="sm:ml-auto" onsubmit="return confirm('Omogućiti javnu provjeru? Time se (ponovno) generira finalni PDF s QR kodom i aktivira javni token.')">
                                @csrf
                                <button type="submit" class="inline-flex min-h-[44px] items-center rounded-xl border border-cyan-300/25 bg-cyan-300/10 px-3.5 text-xs font-semibold text-cyan-100 transition hover:border-cyan-300/45 hover:bg-cyan-300/15">Omogući javnu provjeru</button>
                            </form>
                        @else
                            <x-action href="{{ route('contracts.audit.index', $contract) }}" variant="secondary" size="sm">Audit trag</x-action>
                        @endif
                    </div>
                </x-card>
            @empty
                <div class="md:col-span-2">
                    <x-empty-state title="Nemate spremljenih ugovora." description="Izradite prvi kupoprodajni ugovor kroz builder s live pregledom.">
                        <x-slot:action>
                            <x-action href="{{ route('contracts.create') }}" variant="primary">Novi ugovor</x-action>
                        </x-slot:action>
                    </x-empty-state>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
