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

    // Status tone drives the timeline marker + a small dot; never the only
    // signal (the action name and metadata remain fully readable text).
    $eventTones = [
        'contract.finalized' => 'emerald',
        'contract.final_pdf_generated' => 'emerald',
        'contract.public_verification_enabled' => 'emerald',
        'contract.finalization_failed' => 'red',
        'contract.draft_archived' => 'amber',
    ];
    $dotClasses = [
        'emerald' => 'border-emerald-300/40 bg-emerald-300',
        'amber' => 'border-amber-300/40 bg-amber-300',
        'red' => 'border-red-300/40 bg-red-300',
        'cyan' => 'border-cyan-300/40 bg-cyan-300',
    ];
@endphp

<x-app-layout title="Audit trag ugovora #{{ $contract->id }}" active="contracts" max-width="max-w-4xl">
    <x-page-header
        :back="route('contracts.index')"
        back-label="Natrag na ugovore"
        title="Audit trag ugovora #{{ $contract->id }}"
        subtitle="Kronološki zapis operacija. Najnoviji događaji prikazani su prvi."
    />

    <div class="mt-8">
        <div class="relative space-y-4 before:absolute before:bottom-4 before:left-[0.68rem] before:top-4 before:w-px before:bg-white/10">
            @forelse ($events as $event)
                @php
                    $tone = $eventTones[$event->action] ?? 'cyan';
                    $dot = $dotClasses[$tone] ?? $dotClasses['cyan'];
                @endphp
                <article class="relative pl-9">
                    <span class="absolute left-0 top-5 flex h-[1.4rem] w-[1.4rem] items-center justify-center rounded-full border {{ $dot }} ring-4 ring-slate-950">
                        <span class="h-2 w-2 rounded-full bg-slate-950/70"></span>
                    </span>

                    <x-card class="p-5">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="font-semibold text-white">{{ $eventLabels[$event->action] ?? $event->action }}</h2>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $event->action }}</p>
                            </div>

                            <time class="shrink-0 text-xs text-slate-400" datetime="{{ $event->occurred_at?->toIso8601String() }}">
                                {{ $event->occurred_at?->format('d.m.Y. H:i:s') ?? 'N/A' }}
                            </time>
                        </div>

                        <p class="mt-3 text-xs text-slate-400">Akter: {{ $event->actorUser?->name ?? 'Sustav' }}</p>

                        @if (! empty($event->metadata))
                            <dl class="mt-4 grid gap-2 border-t border-white/10 pt-4 text-xs">
                                @foreach ($event->metadata as $key => $value)
                                    <div class="grid gap-1 sm:grid-cols-[180px_1fr] sm:gap-4">
                                        <dt class="font-medium text-slate-500">{{ str_replace('_', ' ', ucfirst($key)) }}</dt>
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
                    </x-card>
                </article>
            @empty
                <x-empty-state title="Za ovaj ugovor još nema audit događaja." />
            @endforelse
        </div>
    </div>
</x-app-layout>
