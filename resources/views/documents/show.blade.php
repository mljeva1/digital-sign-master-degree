<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detalji dokumenta | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto max-w-4xl px-5 py-8">
        <header class="border-b border-white/10 pb-6">
            <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">Dokument</p>
            <h1 class="mt-3 text-3xl font-semibold text-white">{{ $document->original_filename ?? 'Dokument' }}</h1>
            <p class="mt-2 text-sm text-slate-400">
                Metadata, storage lokacija i SHA-256 provjera integriteta.
            </p>
        </header>

        <main class="mt-8 space-y-6">
            @if (session('success'))
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-2xl border border-red-300/20 bg-red-300/10 px-4 py-3 text-sm text-red-100">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="rounded-[2rem] border border-white/10 bg-slate-900/70 p-6 shadow-2xl shadow-slate-950/40">
                <dl class="grid gap-5">
                    <div>
                        <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Originalni naziv</dt>
                        <dd class="mt-2 text-sm text-white">{{ $document->original_filename ?? 'Dokument' }}</dd>
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

                    <div>
                        <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">SHA-256 hash</dt>
                        <dd class="mt-2 break-all rounded-2xl bg-slate-950/70 px-4 py-3 font-mono text-xs text-emerald-200">
                            {{ $document->sha256 }}
                        </dd>
                    </div>
                </dl>
            </section>

            <div class="flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('documents.download', $document->id) }}" class="inline-flex justify-center rounded-full bg-cyan-300 px-6 py-3 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                    Preuzmi dokument
                </a>

                <form method="POST" action="{{ route('documents.verify', $document->id) }}">
                    @csrf
                    <button type="submit" class="inline-flex w-full justify-center rounded-full border border-emerald-300/20 bg-emerald-300/10 px-6 py-3 text-sm font-bold text-emerald-100 transition hover:bg-emerald-300/15 sm:w-auto">
                        Provjeri SHA-256
                    </button>
                </form>

                <a href="{{ route('documents.index') }}" class="inline-flex justify-center rounded-full border border-white/10 bg-white/5 px-6 py-3 text-sm font-bold text-white transition hover:bg-white/10">
                    Natrag
                </a>
            </div>
        </main>
    </div>
</body>
</html>