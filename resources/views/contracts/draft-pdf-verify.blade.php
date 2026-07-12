<x-app-layout title="Provjera integriteta probnog PDF-a" active="contracts" max-width="max-w-3xl">
    <x-page-header
        :back="route('contracts.index')"
        back-label="Natrag na ugovore"
        title="Provjera integriteta probnog PDF-a"
        subtitle="Ugovor #{{ $contract->id }}"
    />

    <div class="mt-8 space-y-6">
        <div class="flex items-start gap-3 rounded-2xl border px-5 py-5 {{ $valid ? 'border-emerald-300/20 bg-emerald-300/10' : 'border-red-300/20 bg-red-300/10' }}">
            <span class="mt-0.5 shrink-0">
                @if ($valid)
                    <x-badge tone="emerald">Valjan</x-badge>
                @else
                    <x-badge tone="red">Nevaljan</x-badge>
                @endif
            </span>
            <div>
                <p class="text-lg font-semibold {{ $valid ? 'text-emerald-100' : 'text-red-100' }}">
                    {{ $valid ? 'Integritet probnog PDF-a je potvrđen.' : 'Integritet probnog PDF-a nije potvrđen.' }}
                </p>
                <p class="mt-1 text-sm {{ $valid ? 'text-emerald-100/75' : 'text-red-100/75' }}">
                    Provjera uspoređuje spremljeni SHA-256 sa SHA-256 vrijednošću trenutno pohranjene privatne PDF datoteke.
                </p>
            </div>
        </div>

        <x-card>
            <dl class="grid gap-4 text-sm">
                <div>
                    <dt class="text-slate-500">Spremljeni SHA-256</dt>
                    <dd class="mt-1 break-all font-mono text-slate-200">{{ $expectedSha256 }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Trenutno izračunati SHA-256</dt>
                    <dd class="mt-1 break-all font-mono {{ $valid ? 'text-emerald-200' : 'text-red-200' }}">{{ $actualSha256 }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Vrijeme generiranja PDF-a</dt>
                    <dd class="mt-1 text-slate-200">{{ $storedFile->updated_at?->format('d.m.Y. H:i:s') ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Datoteka</dt>
                    <dd class="mt-1 text-slate-200">{{ $storedFile->original_filename ?: basename($storedFile->storage_path) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Relativna putanja u privatnoj pohrani</dt>
                    <dd class="mt-1 break-all font-mono text-slate-200">{{ $storedFile->storage_path }}</dd>
                </div>
            </dl>
        </x-card>

        <x-action href="{{ route('contracts.draft-pdf.show', $contract) }}" variant="primary" target="_blank" rel="noopener">
            Otvori probni PDF
        </x-action>
    </div>
</x-app-layout>
