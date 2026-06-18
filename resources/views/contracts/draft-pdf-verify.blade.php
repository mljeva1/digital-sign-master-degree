<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Provjera integriteta probnog PDF-a | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto max-w-4xl px-5 py-8">
        <header class="flex flex-col gap-4 border-b border-white/10 pb-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold tracking-[0.28em] text-cyan-200">DSMD</p>
                <h1 class="mt-2 text-3xl font-semibold text-white">Provjera integriteta probnog PDF-a</h1>
                <p class="mt-2 text-sm text-slate-400">Ugovor #{{ $contract->id }}</p>
            </div>

            <a href="{{ route('contracts.index') }}" class="rounded-full border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                Natrag na draftove
            </a>
        </header>

        <main class="mt-8">
            <section class="rounded-[2rem] border {{ $valid ? 'border-emerald-300/20 bg-emerald-300/10' : 'border-red-300/20 bg-red-300/10' }} p-6">
                <p class="text-xl font-semibold {{ $valid ? 'text-emerald-100' : 'text-red-100' }}">
                    {{ $valid ? 'Integritet probnog PDF-a je potvrđen.' : 'Integritet probnog PDF-a nije potvrđen.' }}
                </p>
                <p class="mt-2 text-sm {{ $valid ? 'text-emerald-100/75' : 'text-red-100/75' }}">
                    Provjera uspoređuje spremljeni SHA-256 sa SHA-256 vrijednošću trenutno pohranjene privatne PDF datoteke.
                </p>
            </section>

            <dl class="mt-6 grid gap-4 rounded-[2rem] border border-white/10 bg-white/[0.04] p-6 text-sm">
                <div>
                    <dt class="text-slate-500">Rezultat</dt>
                    <dd class="mt-1 font-semibold text-white">{{ $valid ? 'Valjan' : 'Nevaljan' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Spremljeni SHA-256</dt>
                    <dd class="mt-1 break-all font-mono text-slate-200">{{ $expectedSha256 }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Trenutno izračunati SHA-256</dt>
                    <dd class="mt-1 break-all font-mono text-slate-200">{{ $actualSha256 }}</dd>
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

            <a href="{{ route('contracts.draft-pdf.show', $contract) }}" target="_blank" rel="noopener" class="mt-6 inline-flex rounded-full bg-cyan-300 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                Otvori probni PDF
            </a>
        </main>
    </div>
</body>
</html>
