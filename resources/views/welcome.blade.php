{{-- resources/views/welcome.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Digital Sign Master Degree</title>

    <meta name="description" content="Laravel aplikacija za izradu kupoprodajnih ugovora, finalizaciju sa zaključavanjem sadržaja, SHA-256 provjeru integriteta, audit trag i javnu provjeru dokumenata.">

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style>
        html { -webkit-text-size-adjust: 100%; }
        input, select, textarea { font-size: 16px; }
        :where(a, button, input, select, textarea, [tabindex]):focus-visible {
            outline: 2px solid #67e8f9;
            outline-offset: 2px;
            border-radius: 0.5rem;
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.001ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-cyan-400/30 selection:text-cyan-50">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute left-1/2 top-0 h-[38rem] w-[38rem] -translate-x-1/2 rounded-full bg-cyan-500/10 blur-3xl"></div>
        <div class="absolute right-[-12rem] top-32 h-[32rem] w-[32rem] rounded-full bg-indigo-500/10 blur-3xl"></div>
        <div class="absolute bottom-[-16rem] left-[-10rem] h-[34rem] w-[34rem] rounded-full bg-emerald-400/10 blur-3xl"></div>
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(148,163,184,0.08)_1px,transparent_1px),linear-gradient(to_bottom,rgba(148,163,184,0.08)_1px,transparent_1px)] bg-[size:72px_72px] [mask-image:radial-gradient(ellipse_at_top,black_35%,transparent_75%)]"></div>
    </div>

    <header class="sticky top-0 z-50 border-b border-white/10 bg-slate-950/80 backdrop-blur-xl">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-5 py-3 sm:px-6 lg:px-8" aria-label="Glavna navigacija">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-cyan-300/20 bg-cyan-300/10 text-cyan-200">
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="h-5 w-5">
                        <path fill="currentColor" d="M12 2.25 4.5 5.42v5.08c0 4.72 3.2 9.14 7.5 10.25 4.3-1.11 7.5-5.53 7.5-10.25V5.42L12 2.25Zm0 2.18 5.5 2.32v3.75c0 3.62-2.2 6.96-5.5 8.14-3.3-1.18-5.5-4.52-5.5-8.14V6.75L12 4.43Z"/>
                        <path fill="currentColor" d="M10.77 14.8 7.95 12l1.06-1.06 1.76 1.75 4.21-4.21 1.07 1.06-5.28 5.26Z"/>
                    </svg>
                </span>
                <span class="hidden sm:block">
                    <span class="block text-sm font-semibold tracking-[0.24em] text-cyan-100">DSMD</span>
                    <span class="block text-xs text-slate-400">Ugovori i integritet dokumenata</span>
                </span>
            </a>

            <div class="flex items-center gap-2 sm:gap-3">
                @guest
                    <button
                        type="button"
                        data-auth-open="login"
                        class="inline-flex min-h-[44px] items-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white transition hover:border-cyan-300/40 hover:bg-cyan-300/10"
                    >
                        Prijava
                    </button>

                    <button
                        type="button"
                        data-auth-open="register"
                        class="inline-flex min-h-[44px] items-center rounded-xl bg-cyan-300 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-cyan-200"
                    >
                        Registracija
                    </button>
                @endguest

                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex min-h-[44px] items-center rounded-xl bg-cyan-300 px-4 py-2 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                        Dashboard
                    </a>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="inline-flex min-h-[44px] items-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white transition hover:border-red-300/40 hover:bg-red-300/10">
                            Odjava
                        </button>
                    </form>
                @endauth
            </div>
        </nav>
    </header>

    <main>
        <section class="px-5 py-16 sm:px-6 sm:py-20 lg:px-8">
            <div class="mx-auto grid max-w-6xl items-center gap-12 lg:grid-cols-[1.05fr_0.95fr]">
                <div>
                    <p class="inline-flex items-center gap-2 rounded-full border border-cyan-300/20 bg-cyan-300/10 px-4 py-2 text-sm text-cyan-100">
                        <span class="h-2 w-2 rounded-full bg-cyan-300"></span>
                        Laravel · PostgreSQL · Privatna pohrana dokumenata
                    </p>

                    <h1 class="mt-7 max-w-2xl text-4xl font-semibold tracking-tight text-white sm:text-5xl">
                        Izrada, finalizacija i provjera ugovora s dokazivim integritetom.
                    </h1>

                    <p class="mt-6 max-w-2xl text-base leading-8 text-slate-300 sm:text-lg">
                        Kupoprodajni ugovor nastaje kroz builder s live pregledom, finalizacijom se sadržaj
                        trajno zaključava, a integritet dokumenta može se provjeriti SHA-256 hashom —
                        uključujući javnu provjeru bez otkrivanja sadržaja ugovora.
                    </p>

                    <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                        @guest
                            <button
                                type="button"
                                data-auth-open="login"
                                class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-xl bg-cyan-300 px-6 py-3 text-sm font-bold text-slate-950 transition hover:bg-cyan-200"
                            >
                                Uđi u sustav
                                <svg viewBox="0 0 20 20" aria-hidden="true" class="h-4 w-4">
                                    <path fill="currentColor" d="M12.293 4.293a1 1 0 0 1 1.414 0l5 5a1 1 0 0 1 0 1.414l-5 5a1 1 0 1 1-1.414-1.414L15.586 11H2a1 1 0 1 1 0-2h13.586l-3.293-3.293a1 1 0 0 1 0-1.414Z"/>
                                </svg>
                            </button>

                            <button
                                type="button"
                                data-auth-open="register"
                                class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-white/10 bg-white/5 px-6 py-3 text-sm font-bold text-white transition hover:border-white/20 hover:bg-white/10"
                            >
                                Kreiraj račun
                            </button>
                        @endguest

                        @auth
                            <a href="{{ route('dashboard') }}" class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-cyan-300 px-6 py-3 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                                Otvori dashboard
                            </a>

                            <a href="#mogucnosti" class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-white/10 bg-white/5 px-6 py-3 text-sm font-bold text-white transition hover:border-white/20 hover:bg-white/10">
                                Pregled mogućnosti
                            </a>
                        @endauth
                    </div>

                    <dl class="mt-11 grid max-w-2xl grid-cols-3 gap-3">
                        <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                            <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Integritet</dt>
                            <dd class="mt-2 text-2xl font-semibold text-white">SHA-256</dd>
                        </div>
                        <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                            <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Snapshot</dt>
                            <dd class="mt-2 text-2xl font-semibold text-white">JSONB</dd>
                        </div>
                        <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                            <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Zapis</dt>
                            <dd class="mt-2 text-2xl font-semibold text-white">Audit</dd>
                        </div>
                    </dl>
                </div>

                <div class="relative">
                    <div class="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-cyan-300/15 via-indigo-400/10 to-emerald-300/15 blur-2xl" aria-hidden="true"></div>

                    <div class="relative overflow-hidden rounded-[2rem] border border-white/10 bg-slate-900/80 shadow-2xl shadow-slate-950/60 backdrop-blur">
                        <div class="flex items-center justify-between gap-4 border-b border-white/10 px-5 py-4">
                            <div>
                                <p class="text-sm font-semibold text-white">Kupoprodajni ugovor</p>
                                <p class="mt-1 text-xs text-slate-400">Primjer stanja nakon finalizacije</p>
                            </div>
                            <span class="rounded-full border border-emerald-300/25 bg-emerald-300/10 px-3 py-1 text-xs font-semibold text-emerald-100">
                                Finaliziran i zaključan
                            </span>
                        </div>

                        <div class="space-y-3 p-5">
                            <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 px-4 py-3">
                                <span class="text-sm text-slate-400">Sadržaj ugovora</span>
                                <span class="text-sm font-medium text-white">Trajno zaključan</span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 px-4 py-3">
                                <span class="text-sm text-slate-400">SHA-256 snapshota</span>
                                <span class="font-mono text-xs text-cyan-200">9f2e…c41a</span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 px-4 py-3">
                                <span class="text-sm text-slate-400">Finalni PDF</span>
                                <span class="text-sm font-medium text-white">Privatna pohrana</span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 px-4 py-3">
                                <span class="text-sm text-slate-400">Javna provjera</span>
                                <span class="text-sm font-medium text-emerald-200">Aktivna (QR + token)</span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 px-4 py-3">
                                <span class="text-sm text-slate-400">Audit događaji</span>
                                <span class="text-sm font-medium text-white">Zabilježeni</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="mogucnosti" class="px-5 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">Mogućnosti</p>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                        Što sustav danas stvarno radi.
                    </h2>
                    <p class="mt-5 text-base leading-8 text-slate-300">
                        Svaka funkcionalnost u nastavku implementirana je i pokrivena testovima —
                        bez marketinških obećanja.
                    </p>
                </div>

                <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article class="rounded-3xl border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">Builder s live pregledom</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Izrada kupoprodajnog ugovora kroz strukturiranu formu s trenutnim pregledom dokumenta
                            i automatskim popunjavanjem iz kataloga vozila i vlastitog profila.
                        </p>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">Finalizacija i zaključavanje</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Finalizacijom se snapshot ugovora trajno zaključava — sadržaj se nakon toga
                            više ne može uređivati.
                        </p>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">SHA-256 integritet</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Zaključani snapshot i generirani PDF dokumenti imaju SHA-256 hash vrijednosti
                            koje se mogu ponovno provjeriti u svakom trenutku.
                        </p>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">Privatni PDF dokumenti</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Probni i finalni PDF čuvaju se u privatnoj pohrani i dostupni su isključivo
                            vlasniku ugovora — nikad kao javni asset.
                        </p>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">Audit trag</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Ključne operacije nad ugovorom ostavljaju kronološki zapis: tko, kada i s kojim
                            rezultatom — bez osobnih podataka u metapodacima.
                        </p>
                    </article>

                    <article class="rounded-3xl border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">Javna provjera</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Finalizirani ugovor može dobiti javnu provjeru putem tokena i QR koda:
                            gost vidi status i hash vrijednosti, nikad sadržaj ugovora.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section id="proces" class="px-5 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl rounded-[2rem] border border-white/10 bg-white/[0.04] p-6 sm:p-8 lg:p-10">
                <div class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.28em] text-emerald-200">Proces</p>
                        <h2 class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                            Od unosa do provjerljivog dokumenta.
                        </h2>
                        <p class="mt-5 text-base leading-8 text-slate-300">
                            Svaki korak ostavlja tehnički trag: snapshot podataka, hash dokumenta i audit zapis.
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @php
                            $steps = [
                                ['01', 'Unos podataka', 'Prodavatelj, kupac, vozilo i uvjeti plaćanja unose se kroz builder s live pregledom.'],
                                ['02', 'Snapshot ugovora', 'Spremanjem nastaje JSONB snapshot — jedini izvor istine za sadržaj dokumenta.'],
                                ['03', 'Finalizacija', 'Snapshot se zaključava i računa se njegov SHA-256; uređivanje je trajno onemogućeno.'],
                                ['04', 'PDF i javna provjera', 'Finalni PDF nastaje iz zaključane verzije, a javna provjera potvrđuje status i hash.'],
                            ];
                        @endphp

                        @foreach ($steps as [$number, $title, $description])
                            <div class="rounded-3xl border border-white/10 bg-slate-950/50 p-5">
                                <span class="text-sm font-semibold text-cyan-200">{{ $number }}</span>
                                <h3 class="mt-4 text-lg font-semibold text-white">{{ $title }}</h3>
                                <p class="mt-3 text-sm leading-6 text-slate-400">{{ $description }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section id="potpisivanje" class="px-5 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl rounded-[2rem] border border-white/10 bg-white/[0.04] p-6 sm:p-8">
                <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">Digitalno potpisivanje</p>
                <h2 class="mt-4 text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                    Lokalni akademski kriptografski potpis dokumenta.
                </h2>
                <p class="mt-4 max-w-3xl text-base leading-8 text-slate-300">
                    Nad zaključanim finalnim PDF-om može se izraditi lokalni akademski detached
                    CMS/PKCS#7 potpis. Potpis se pohranjuje kao zaseban <code class="text-cyan-200">.p7s</code>
                    artefakt — sam PDF se ne mijenja — a njegov se status provjerava kroz javnu provjeru,
                    zasebno za integritet PDF-a, integritet potpisa, kriptografsku valjanost i povjerenje
                    prema lokalnom testnom Root CA.
                </p>
                <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-400">
                    Radi se o lokalnom testnom PKI-ju sa self-signed testnim trust anchorom.
                    Nije PAdES, eIDAS ni kvalificirani elektronički potpis (QES) i nema pravnu snagu.
                    Finalizacija ugovora zasebna je aplikacijska radnja: ona zaključava sadržaj i daje
                    provjerljiv SHA-256 integritet, ali sama po sebi nije digitalni potpis.
                </p>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/10 px-5 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto flex max-w-6xl flex-col gap-4 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ now()->year }} Digital Sign Master Degree. Lokalni akademski projekt.</p>
            <p class="text-slate-400">Laravel · PostgreSQL · Tailwind CSS · Audit-first arhitektura</p>
        </div>
    </footer>

    @guest
        <div
            id="authModal"
            class="fixed inset-0 z-[100] hidden items-center justify-center px-4 py-8"
            role="dialog"
            aria-modal="true"
            aria-labelledby="authModalTitle"
        >
            <div
                data-auth-close
                class="absolute inset-0 bg-slate-950/80 backdrop-blur-md"
                aria-hidden="true"
            ></div>

            <div class="relative max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-[2rem] border border-white/10 bg-slate-900/95 p-6 shadow-2xl shadow-slate-950/80 sm:p-8">
                <button
                    type="button"
                    data-auth-close
                    class="absolute right-5 top-5 flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-300 transition hover:bg-white/10 hover:text-white"
                    aria-label="Zatvori modal"
                >
                    <svg viewBox="0 0 20 20" class="h-5 w-5" aria-hidden="true">
                        <path fill="currentColor" d="M5.28 4.22a.75.75 0 0 0-1.06 1.06L8.94 10l-4.72 4.72a.75.75 0 1 0 1.06 1.06L10 11.06l4.72 4.72a.75.75 0 1 0 1.06-1.06L11.06 10l4.72-4.72a.75.75 0 0 0-1.06-1.06L10 8.94 5.28 4.22Z"/>
                    </svg>
                </button>

                <div class="mb-6 pr-10">
                    <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">Siguran pristup</p>
                    <h2 id="authModalTitle" class="mt-3 text-3xl font-semibold tracking-tight text-white">
                        Pristup sustavu
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-slate-400">
                        Prijavi se postojećim računom ili napravi novi korisnički račun za pristup dashboardu.
                    </p>
                </div>

                @if (session('success'))
                    <div class="mb-5 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100" role="status">
                        {{ session('success') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-5 rounded-2xl border border-red-300/20 bg-red-300/10 px-4 py-3 text-sm text-red-100" role="alert">
                        <p class="font-semibold">Provjeri unesene podatke:</p>
                        <ul class="mt-2 list-inside list-disc space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div
                    class="mb-6 grid grid-cols-2 rounded-full border border-white/10 bg-slate-950/70 p-1"
                    role="tablist"
                    aria-label="Prijava ili registracija"
                >
                    <button
                        type="button"
                        id="authTabLogin"
                        data-auth-tab="login"
                        role="tab"
                        aria-selected="false"
                        aria-controls="authPanelLogin"
                        class="min-h-[44px] rounded-full px-4 py-2.5 text-sm font-semibold transition"
                    >
                        Prijava
                    </button>

                    <button
                        type="button"
                        id="authTabRegister"
                        data-auth-tab="register"
                        role="tab"
                        aria-selected="false"
                        aria-controls="authPanelRegister"
                        class="min-h-[44px] rounded-full px-4 py-2.5 text-sm font-semibold transition"
                    >
                        Registracija
                    </button>
                </div>

                <div
                    id="authPanelLogin"
                    data-auth-panel="login"
                    role="tabpanel"
                    aria-labelledby="authTabLogin"
                >
                    <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
                        @csrf

                        <input type="hidden" name="auth_form" value="login">

                        <div>
                            <label for="login_email" class="block text-sm font-medium text-slate-200">Email</label>
                            <input
                                id="login_email"
                                name="email"
                                type="email"
                                value="{{ old('auth_form') === 'login' ? old('email') : '' }}"
                                required
                                autocomplete="email"
                                class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                                placeholder="ime@primjer.hr"
                            >
                        </div>

                        <div>
                            <label for="login_password" class="block text-sm font-medium text-slate-200">Lozinka</label>
                            <div class="relative mt-2">
                                <input
                                    id="login_password"
                                    name="password"
                                    type="password"
                                    required
                                    autocomplete="current-password"
                                    class="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 pr-24 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                                    placeholder="Unesi lozinku"
                                >
                                <button
                                    type="button"
                                    data-password-toggle="login_password"
                                    aria-pressed="false"
                                    class="absolute inset-y-1.5 right-1.5 rounded-xl px-3 text-xs font-semibold text-slate-400 transition hover:bg-white/5 hover:text-white"
                                >
                                    Prikaži
                                </button>
                            </div>
                        </div>

                        <label class="flex min-h-[44px] items-center gap-3 text-sm text-slate-300">
                            <input
                                type="checkbox"
                                name="remember"
                                value="1"
                                class="h-4 w-4 rounded border-white/10 bg-slate-950 text-cyan-300"
                            >
                            Zapamti me
                        </label>

                        <button type="submit" class="min-h-[44px] w-full rounded-xl bg-cyan-300 px-6 py-3 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                            Prijavi se
                        </button>
                    </form>
                </div>

                <div
                    id="authPanelRegister"
                    data-auth-panel="register"
                    role="tabpanel"
                    aria-labelledby="authTabRegister"
                    class="hidden"
                >
                    <form method="POST" action="{{ route('register.store') }}" class="space-y-5">
                        @csrf

                        <input type="hidden" name="auth_form" value="register">

                        <div>
                            <label for="register_name" class="block text-sm font-medium text-slate-200">Ime i prezime</label>
                            <input
                                id="register_name"
                                name="name"
                                type="text"
                                value="{{ old('auth_form') === 'register' ? old('name') : '' }}"
                                required
                                autocomplete="name"
                                class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/20"
                                placeholder="Ime i prezime"
                            >
                        </div>

                        <div>
                            <label for="register_email" class="block text-sm font-medium text-slate-200">Email</label>
                            <input
                                id="register_email"
                                name="email"
                                type="email"
                                value="{{ old('auth_form') === 'register' ? old('email') : '' }}"
                                required
                                autocomplete="email"
                                class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/20"
                                placeholder="ime@primjer.hr"
                            >
                        </div>

                        <div>
                            <label for="register_password" class="block text-sm font-medium text-slate-200">Lozinka</label>
                            <div class="relative mt-2">
                                <input
                                    id="register_password"
                                    name="password"
                                    type="password"
                                    required
                                    autocomplete="new-password"
                                    class="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 pr-24 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/20"
                                    placeholder="Minimalno 8 znakova"
                                >
                                <button
                                    type="button"
                                    data-password-toggle="register_password"
                                    aria-pressed="false"
                                    class="absolute inset-y-1.5 right-1.5 rounded-xl px-3 text-xs font-semibold text-slate-400 transition hover:bg-white/5 hover:text-white"
                                >
                                    Prikaži
                                </button>
                            </div>
                        </div>

                        <div>
                            <label for="register_password_confirmation" class="block text-sm font-medium text-slate-200">Potvrda lozinke</label>
                            <div class="relative mt-2">
                                <input
                                    id="register_password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    required
                                    autocomplete="new-password"
                                    class="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 pr-24 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/20"
                                    placeholder="Ponovi lozinku"
                                >
                                <button
                                    type="button"
                                    data-password-toggle="register_password_confirmation"
                                    aria-pressed="false"
                                    class="absolute inset-y-1.5 right-1.5 rounded-xl px-3 text-xs font-semibold text-slate-400 transition hover:bg-white/5 hover:text-white"
                                >
                                    Prikaži
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="min-h-[44px] w-full rounded-xl bg-emerald-300 px-6 py-3 text-sm font-bold text-slate-950 transition hover:bg-emerald-200">
                            Registriraj se
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endguest

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('authModal');

            if (! modal) {
                return;
            }

            const openButtons = document.querySelectorAll('[data-auth-open]');
            const closeButtons = modal.querySelectorAll('[data-auth-close]');
            const tabButtons = modal.querySelectorAll('[data-auth-tab]');
            const panels = modal.querySelectorAll('[data-auth-panel]');

            const activeTabClasses = ['bg-cyan-300', 'text-slate-950', 'shadow-sm'];
            const inactiveTabClasses = ['text-slate-300', 'hover:bg-white/5', 'hover:text-white'];

            // Element that opened the modal — focus returns to it on close.
            let modalOpener = null;

            const setTab = (tabName) => {
                tabButtons.forEach((button) => {
                    const isActive = button.dataset.authTab === tabName;

                    button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    button.classList.remove(...activeTabClasses, ...inactiveTabClasses);
                    button.classList.add(...(isActive ? activeTabClasses : inactiveTabClasses));
                });

                panels.forEach((panel) => {
                    panel.classList.toggle('hidden', panel.dataset.authPanel !== tabName);
                });
            };

            const focusFirstInput = (tabName) => {
                const firstInput = modal.querySelector(`[data-auth-panel="${tabName}"] input:not([type="hidden"])`);

                if (firstInput) {
                    setTimeout(() => firstInput.focus(), 50);
                }
            };

            const openModal = (tabName = 'login', opener = null) => {
                modalOpener = opener;

                setTab(tabName);

                modal.classList.remove('hidden');
                modal.classList.add('flex');

                document.body.classList.add('overflow-hidden');

                focusFirstInput(tabName);
            };

            const closeModal = () => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');

                document.body.classList.remove('overflow-hidden');

                if (modalOpener && document.contains(modalOpener)) {
                    modalOpener.focus();
                }

                modalOpener = null;
            };

            openButtons.forEach((button) => {
                button.addEventListener('click', () => openModal(button.dataset.authOpen, button));
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            tabButtons.forEach((button) => {
                button.addEventListener('click', () => setTab(button.dataset.authTab));

                // Arrow keys move between the two tabs, per the ARIA tabs pattern.
                button.addEventListener('keydown', (event) => {
                    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                        return;
                    }

                    event.preventDefault();

                    const other = [...tabButtons].find((candidate) => candidate !== button);

                    if (other) {
                        setTab(other.dataset.authTab);
                        other.focus();
                    }
                });
            });

            document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
                toggle.addEventListener('click', () => {
                    const input = document.getElementById(toggle.dataset.passwordToggle);

                    if (! input) {
                        return;
                    }

                    const reveal = input.type === 'password';

                    input.type = reveal ? 'text' : 'password';
                    toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                    toggle.textContent = reveal ? 'Sakrij' : 'Prikaži';
                });
            });

            document.addEventListener('keydown', (event) => {
                if (modal.classList.contains('hidden')) {
                    return;
                }

                if (event.key === 'Escape') {
                    closeModal();

                    return;
                }

                // Minimal focus trap: keep Tab cycling inside the open modal.
                if (event.key === 'Tab') {
                    const focusable = [...modal.querySelectorAll(
                        'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
                    )].filter((element) => element.offsetParent !== null);

                    if (focusable.length === 0) {
                        return;
                    }

                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];

                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            });

            const initialModal = @json(old('auth_form') ?: session('auth_modal'));

            if (initialModal === 'login' || initialModal === 'register') {
                openModal(initialModal);
            } else {
                setTab('login');
            }
        });
    </script>
</body>
</html>
