<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokumenti | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto max-w-7xl px-5 py-8">
        <header class="flex flex-col gap-4 border-b border-white/10 pb-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold tracking-[0.28em] text-cyan-200">DSMD</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Dokumenti</h1>
                <p class="mt-2 text-sm text-slate-400">
                    Private storage, metadata i SHA-256 integritet dokumenta.
                </p>
            </div>

            <div class="flex gap-3">
                <a href="{{ route('dashboard') }}" class="rounded-full border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                    Dashboard
                </a>

                <a href="{{ route('documents.create') }}" class="rounded-full bg-cyan-300 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                    Upload dokumenta
                </a>
            </div>
        </header>

        <main class="mt-8">
            @if (session('success'))
                <div class="mb-6 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-2xl border border-red-300/20 bg-red-300/10 px-4 py-3 text-sm text-red-100">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="overflow-hidden rounded-[2rem] border border-white/10 bg-slate-900/70 shadow-2xl shadow-slate-950/40">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-white/10">
                        <thead class="bg-white/[0.03]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Naziv</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">MIME</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Veličina</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">SHA-256</th>
                                <th class="px-5 py-4 text-right text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Akcije</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-white/10">
                            @forelse ($documents as $document)
                                <tr class="transition hover:bg-white/[0.03]">
                                    <td class="px-5 py-4 text-sm font-medium text-white">
                                        {{ $document->original_name }}
                                    </td>

                                    <td class="px-5 py-4 text-sm text-slate-400">
                                        {{ $document->mime_type ?? 'N/A' }}
                                    </td>

                                    <td class="px-5 py-4 text-sm text-slate-400">
                                        {{ number_format($document->size_bytes / 1024, 2) }} KB
                                    </td>

                                    <td class="px-5 py-4">
                                        <code class="block max-w-xs truncate rounded-xl bg-slate-950/70 px-3 py-2 text-xs text-cyan-200">
                                            {{ $document->sha256_hash }}
                                        </code>
                                    </td>

                                    <td class="px-5 py-4">
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ route('documents.show', $document->id) }}" class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white transition hover:bg-white/10">
                                                Detalji
                                            </a>

                                            <a href="{{ route('documents.download', $document->id) }}" class="rounded-full bg-cyan-300 px-4 py-2 text-xs font-bold text-slate-950 transition hover:bg-cyan-200">
                                                Preuzmi
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">
                                        Još nema uploadanih dokumenata.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-white/10 px-5 py-4">
                    {{ $documents->links() }}
                </div>
            </div>
        </main>
    </div>
</body>
</html>