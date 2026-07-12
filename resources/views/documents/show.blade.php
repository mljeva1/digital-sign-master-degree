<x-app-layout title="Detalji dokumenta" active="documents" max-width="max-w-3xl">
    <x-page-header
        :back="route('documents.index')"
        back-label="Natrag na dokumente"
        :title="$document->original_filename ?? 'Dokument'"
        subtitle="Metadata dokumenta i SHA-256 provjera integriteta."
    />

    <div class="mt-8 space-y-6">
        <x-flash />

        <x-card>
            <dl class="grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Originalni naziv</dt>
                    <dd class="mt-2 break-words text-sm text-white">{{ $document->original_filename ?? 'Dokument' }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">MIME type</dt>
                    <dd class="mt-2 text-sm text-white">{{ $document->mime_type ?? 'N/A' }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Veličina</dt>
                    <dd class="mt-2 text-sm text-white">{{ $document->size_bytes !== null ? number_format($document->size_bytes / 1024, 2).' KB' : 'N/A' }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Vrijeme uploada</dt>
                    <dd class="mt-2 text-sm text-white">{{ optional($document->created_at)->format('d.m.Y. H:i') ?? 'N/A' }}</dd>
                </div>

                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">SHA-256 hash</dt>
                    <dd class="mt-2 break-all rounded-2xl bg-slate-950/70 px-4 py-3 font-mono text-xs text-emerald-200">{{ $document->sha256 }}</dd>
                </div>
            </dl>
        </x-card>

        <div class="flex flex-col gap-3 sm:flex-row">
            <x-action href="{{ route('documents.download', $document->id) }}" variant="primary">Preuzmi dokument</x-action>

            <form method="POST" action="{{ route('documents.verify', $document->id) }}">
                @csrf
                <x-action type="submit" variant="success" class="w-full sm:w-auto">Provjeri SHA-256</x-action>
            </form>

            <x-action href="{{ route('documents.index') }}" variant="secondary">Natrag</x-action>
        </div>
    </div>
</x-app-layout>
