<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto flex min-h-screen max-w-5xl flex-col px-5 py-8">
        <header class="flex items-center justify-between border-b border-white/10 pb-5">
            <div>
                <p class="text-sm font-semibold tracking-[0.28em] text-cyan-200">DSMD</p>
                <h1 class="mt-2 text-2xl font-semibold text-white">Dashboard</h1>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('contracts.index') }}" class="inline-flex rounded-full border border-cyan-300/20 bg-cyan-300/10 px-6 py-3 text-sm font-bold text-cyan-100 transition hover:bg-cyan-300/15">
                    Ugovori
                </a>
                <a href="{{ route('documents.index') }}" class="inline-flex rounded-full bg-cyan-300 px-6 py-3 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                    Dokumenti
                </a>
                <a href="{{ route('profile.edit') }}" class="inline-flex rounded-full border border-white/10 bg-white/5 px-6 py-3 text-sm font-bold text-white transition hover:bg-white/10">
                    Profil
                </a>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-full border border-white/10 bg-white/10 px-5 py-2 text-sm font-semibold text-white transition hover:bg-white/15">
                    Odjava
                </button>
            </form>
        </header>

        <main class="grid flex-1 place-items-center">
            <div class="w-full rounded-[2rem] border border-white/10 bg-white/[0.04] p-8 shadow-2xl shadow-slate-950/50">
                @if (session('success'))
                    <div class="mb-6 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">
                        {{ session('success') }}
                    </div>
                @endif

                <p class="text-sm uppercase tracking-[0.28em] text-slate-500">Prijavljeni korisnik</p>
                <h2 class="mt-3 text-3xl font-semibold text-white">{{ auth()->user()->name }}</h2>
                <p class="mt-2 text-slate-400">{{ auth()->user()->email }}</p><br>
                <a href="{{ route('contracts.create') }}" class="block rounded-3xl border border-cyan-300/20 bg-cyan-300/10 p-5 transition hover:bg-cyan-300/15">
                    <h3 class="text-lg font-semibold text-cyan-100">Novi ugovor</h3>
                    <p class="mt-2 text-sm leading-6 text-cyan-100/80">
                        Izrada kupoprodajnog ugovora kroz formu i live HTML preview.
                    </p>
                </a>
                <div class="mt-8 rounded-3xl border border-cyan-300/20 bg-cyan-300/10 p-5">
                    <h3 class="text-lg font-semibold text-cyan-100">Auth sloj je aktivan</h3>
                    <p class="mt-2 text-sm leading-6 text-cyan-100/80">
                        Sljedeći korak može biti prvi poslovni kontroler za dokumente, ugovore ili kupce.
                    </p>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
