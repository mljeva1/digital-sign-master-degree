<x-app-layout title="Upload dokumenta" active="documents" max-width="max-w-2xl">
    <x-page-header
        :back="route('documents.index')"
        back-label="Natrag na dokumente"
        title="Spremi dokument u private storage"
        subtitle="Datoteka neće biti javni asset. Nakon spremanja računa se SHA-256 hash za provjeru integriteta."
    />

    <div class="mt-8">
        <x-flash />

        <x-card>
            <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf

                <div>
                    <label for="document" class="block text-sm font-medium text-slate-200">Dokument</label>
                    <input
                        id="document"
                        name="document"
                        type="file"
                        required
                        accept=".pdf,.doc,.docx"
                        aria-describedby="document_help"
                        class="mt-2 w-full cursor-pointer rounded-2xl border border-dashed border-white/10 bg-slate-950/70 px-4 py-6 text-sm text-slate-300 outline-none transition file:mr-4 file:rounded-full file:border-0 file:bg-cyan-300 file:px-4 file:py-2 file:text-sm file:font-bold file:text-slate-950 hover:border-cyan-300/40"
                    >
                    <p id="document_help" class="mt-2 text-xs text-slate-500">Dozvoljeno: PDF, DOC, DOCX. Maksimalno: 10 MB.</p>
                    @error('document')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <x-action type="submit" variant="primary">Spremi dokument</x-action>
                    <x-action href="{{ route('documents.index') }}" variant="secondary">Odustani</x-action>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
