<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload dokumenta | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto grid min-h-screen max-w-3xl place-items-center px-5 py-8">
        <div class="w-full rounded-[2rem] border border-white/10 bg-slate-900/80 p-6 shadow-2xl shadow-slate-950/60 sm:p-8">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">Upload dokumenta</p>
                <h1 class="mt-3 text-3xl font-semibold text-white">Spremi dokument u private storage</h1>
                <p class="mt-3 text-sm leading-6 text-slate-400">
                    Datoteka neće biti javni asset. Nakon spremanja računa se SHA-256 hash za provjeru integriteta.
                </p>
            </div>

            @if ($errors->any())
                <div class="mt-6 rounded-2xl border border-red-300/20 bg-red-300/10 px-4 py-3 text-sm text-red-100">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="mt-8 space-y-6">
                @csrf

                <div>
                    <label for="title" class="block text-sm font-medium text-slate-200">
                        Naziv dokumenta
                    </label>

                    <input
                        id="title"
                        name="title"
                        type="text"
                        value="{{ old('title') }}"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                        placeholder="Npr. Kupoprodajni ugovor"
                    >

                    <p class="mt-2 text-xs text-slate-500">
                        Ako ostaviš prazno, koristit će se originalni naziv datoteke.
                    </p>
                </div>

                <div>
                    <label for="document" class="block text-sm font-medium text-slate-200">
                        Dokument
                    </label>

                    <input
                        id="document"
                        name="document"
                        type="file"
                        required
                        accept=".pdf,.doc,.docx"
                        class="mt-2 w-full cursor-pointer rounded-2xl border border-dashed border-white/10 bg-slate-950/70 px-4 py-6 text-sm text-slate-300 outline-none transition file:mr-4 file:rounded-full file:border-0 file:bg-cyan-300 file:px-4 file:py-2 file:text-sm file:font-bold file:text-slate-950 hover:border-cyan-300/40"
                    >

                    <p class="mt-2 text-xs text-slate-500">
                        Dozvoljeno: PDF, DOC, DOCX. Maksimalno: 10 MB.
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <button type="submit" class="inline-flex justify-center rounded-full bg-cyan-300 px-6 py-3 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                        Spremi dokument
                    </button>

                    <a href="{{ route('documents.index') }}" class="inline-flex justify-center rounded-full border border-white/10 bg-white/5 px-6 py-3 text-sm font-bold text-white transition hover:bg-white/10">
                        Odustani
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>