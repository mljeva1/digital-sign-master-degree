<x-app-layout title="Dokumenti" active="documents">
    <x-page-header
        title="Dokumenti"
        subtitle="Privatni upload uz metadata i SHA-256 provjeru integriteta dokumenta."
    >
        <x-slot:actions>
            <x-action href="{{ route('documents.create') }}" variant="primary">Upload dokumenta</x-action>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-8">
        <x-flash />

        @if ($documents->count() > 0)
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($documents as $document)
                    <x-card class="flex flex-col p-5">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-300/10 text-cyan-200 ring-1 ring-cyan-300/20">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true"><path fill="currentColor" d="M6 2.75A2.25 2.25 0 0 0 3.75 5v14A2.25 2.25 0 0 0 6 21.25h12A2.25 2.25 0 0 0 20.25 19V8.31a2.25 2.25 0 0 0-.66-1.59l-3.31-3.31a2.25 2.25 0 0 0-1.59-.66H6Zm8 1.5v3A1.75 1.75 0 0 0 15.75 9h3v10a.75.75 0 0 1-.75.75H6a.75.75 0 0 1-.75-.75V5A.75.75 0 0 1 6 4.25h8Z"/></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-white">{{ $document->original_filename ?? 'Dokument' }}</p>
                                <p class="mt-0.5 text-xs text-slate-400">
                                    {{ $document->mime_type ?? 'N/A' }}
                                    @if ($document->size_bytes !== null)
                                        · {{ number_format($document->size_bytes / 1024, 1) }} KB
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">SHA-256</p>
                            <code class="mt-1 block truncate rounded-lg bg-slate-950/70 px-3 py-2 text-xs text-cyan-200">{{ $document->sha256 }}</code>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2 border-t border-white/10 pt-4">
                            <x-action href="{{ route('documents.show', $document->id) }}" variant="secondary" size="sm">Detalji</x-action>
                            <x-action href="{{ route('documents.download', $document->id) }}" variant="primary" size="sm">Preuzmi</x-action>
                        </div>
                    </x-card>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $documents->links() }}
            </div>
        @else
            <x-empty-state title="Još nema uploadanih dokumenata." description="Spremi PDF, DOC ili DOCX u privatni storage — SHA-256 hash računa se automatski.">
                <x-slot:action>
                    <x-action href="{{ route('documents.create') }}" variant="primary">Upload dokumenta</x-action>
                </x-slot:action>
            </x-empty-state>
        @endif
    </div>
</x-app-layout>
