<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit trag ugovora #{{ $contract->id }} | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    @php
        $eventLabels = [
            'contract.snapshot_saved' => 'Snapshot spremljen',
            'contract.draft_archived' => 'Draft arhiviran',
            'contract.draft_pdf_generated' => 'Probni PDF generiran',
            'contract.draft_pdf_verified' => 'Integritet probnog PDF-a provjeren',
            'contract.required_fields_validated' => 'Obavezna polja provjerena',
            'contract.finalization_failed' => 'Finalizacija odbijena',
            'contract.finalized' => 'Ugovor finaliziran',
            'contract.final_pdf_generated' => 'Finalni PDF generiran',
            'contract.final_pdf_viewed' => 'Finalni PDF prikazan',
            'contract.final_pdf_verified' => 'Integritet finalnog PDF-a provjeren',
            'contract.public_verification_enabled' => 'Javna provjera omogućena',
            'contract.public_verification_viewed' => 'Javna provjera pregledana',
            'contract.audit_viewed' => 'Audit trag pregledan',
        ];
    @endphp

    <div class="mx-auto max-w-4xl px-5 py-8">
        <header class="flex flex-col gap-5 border-b border-white/10 pb-6 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold tracking-[0.28em] text-cyan-200">DSMD · AUDIT</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Audit trag ugovora #{{ $contract->id }}</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Kronološki zapis operacija. Najnoviji događaji prikazani su prvi.
                </p>
            </div>

            <a href="{{ route('contracts.index') }}" class="inline-flex h-10 items-center rounded-xl border border-white/10 bg-white/[0.03] px-4 text-sm font-medium text-slate-300 transition hover:border-white/20 hover:bg-white/[0.07] hover:text-white">
                Natrag na ugovore
            </a>
        </header>

        <main class="mt-8">
            <div class="relative space-y-4 before:absolute before:bottom-4 before:left-[0.68rem] before:top-4 before:w-px before:bg-white/10">
                @forelse ($events as $event)
                    <article class="relative pl-9">
                        <span class="absolute left-0 top-5 h-[1.4rem] w-[1.4rem] rounded-full border border-cyan-300/30 bg-slate-950 ring-4 ring-slate-950">
                            <span class="absolute inset-[0.38rem] rounded-full bg-cyan-300"></span>
                        </span>

                        <div class="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h2 class="font-semibold text-white">
                                        {{ $eventLabels[$event->action] ?? $event->action }}
                                    </h2>
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $event->action }}</p>
                                </div>

                                <time class="text-xs text-slate-400" datetime="{{ $event->occurred_at?->toIso8601String() }}">
                                    {{ $event->occurred_at?->format('d.m.Y. H:i:s') ?? 'N/A' }}
                                </time>
                            </div>

                            <p class="mt-3 text-xs text-slate-400">
                                Akter: {{ $event->actorUser?->name ?? 'Sustav' }}
                            </p>

                            @if (! empty($event->metadata))
                                <dl class="mt-4 grid gap-2 border-t border-white/10 pt-4 text-xs">
                                    @foreach ($event->metadata as $key => $value)
                                        <div class="grid gap-1 sm:grid-cols-[180px_1fr] sm:gap-4">
                                            <dt class="font-medium text-slate-500">
                                                {{ str_replace('_', ' ', ucfirst($key)) }}
                                            </dt>
                                            <dd class="break-all font-mono text-slate-300">
                                                @if (is_bool($value))
                                                    {{ $value ? 'true' : 'false' }}
                                                @elseif (is_array($value))
                                                    {{ implode(', ', array_map(static fn ($item) => (string) $item, $value)) ?: '—' }}
                                                @else
                                                    {{ $value }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-white/15 bg-white/[0.03] px-6 py-12 text-center text-sm text-slate-400">
                        Za ovaj ugovor još nema audit događaja.
                    </div>
                @endforelse
            </div>
        </main>
    </div>
</body>
</html>
