{{-- resources/views/welcome.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Digital Sign Master Degree</title>

    <meta name="description" content="Profesionalna Laravel aplikacija za upravljanje ugovorima, dokumentima, certifikatima i digitalnim potpisima.">

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-cyan-400/30 selection:text-cyan-50">
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute left-1/2 top-0 h-[38rem] w-[38rem] -translate-x-1/2 rounded-full bg-cyan-500/10 blur-3xl"></div>
        <div class="absolute right-[-12rem] top-32 h-[32rem] w-[32rem] rounded-full bg-indigo-500/10 blur-3xl"></div>
        <div class="absolute bottom-[-16rem] left-[-10rem] h-[34rem] w-[34rem] rounded-full bg-emerald-400/10 blur-3xl"></div>
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(148,163,184,0.08)_1px,transparent_1px),linear-gradient(to_bottom,rgba(148,163,184,0.08)_1px,transparent_1px)] bg-[size:72px_72px] [mask-image:radial-gradient(ellipse_at_top,black_35%,transparent_75%)]"></div>
    </div>

    <header class="sticky top-0 z-50 border-b border-white/10 bg-slate-950/70 backdrop-blur-xl">
        <nav class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 sm:px-6 lg:px-8" aria-label="Glavna navigacija">
            <a href="#" class="group flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-2xl border border-cyan-300/20 bg-cyan-300/10 shadow-lg shadow-cyan-950/40">
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="h-5 w-5 text-cyan-200">
                        <path fill="currentColor" d="M12 2.25 4.5 5.42v5.08c0 4.72 3.2 9.14 7.5 10.25 4.3-1.11 7.5-5.53 7.5-10.25V5.42L12 2.25Zm0 2.18 5.5 2.32v3.75c0 3.62-2.2 6.96-5.5 8.14-3.3-1.18-5.5-4.52-5.5-8.14V6.75L12 4.43Z"/>
                        <path fill="currentColor" d="M10.77 14.8 7.95 12l1.06-1.06 1.76 1.75 4.21-4.21 1.07 1.06-5.28 5.26Z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold tracking-[0.28em] text-cyan-200">DSMD</p>
                    <p class="text-xs text-slate-400">Digital Signing Platform</p>
                </div>
            </a>

            <div class="hidden items-center gap-8 md:flex">
                <a href="#platform" class="text-sm font-medium text-slate-300 transition hover:text-white">Platforma</a>
                <a href="#workflow" class="text-sm font-medium text-slate-300 transition hover:text-white">Proces</a>
                <a href="#security" class="text-sm font-medium text-slate-300 transition hover:text-white">Sigurnost</a>
                <a href="#data-model" class="text-sm font-medium text-slate-300 transition hover:text-white">Model podataka</a>
            </div>

            <a href="#demo" class="hidden rounded-full border border-white/10 bg-white/10 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:border-cyan-300/40 hover:bg-cyan-300/10 md:inline-flex">
                Pregled sustava
            </a>
        </nav>
    </header>

    <main>
        <section class="relative overflow-hidden px-5 py-20 sm:px-6 sm:py-24 lg:px-8 lg:py-28">
            <div class="mx-auto grid max-w-7xl items-center gap-14 lg:grid-cols-[1.02fr_0.98fr]">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-emerald-300/20 bg-emerald-300/10 px-4 py-2 text-sm text-emerald-100 shadow-lg shadow-emerald-950/20">
                        <span class="h-2 w-2 rounded-full bg-emerald-300 shadow-[0_0_18px_rgba(110,231,183,0.9)]"></span>
                        Lokalni razvoj · PostgreSQL · Laravel · Digitalni potpisi
                    </div>

                    <h1 class="mt-8 max-w-5xl text-4xl font-semibold tracking-tight text-white sm:text-5xl lg:text-7xl">
                        Profesionalno upravljanje ugovorima i digitalnim potpisivanjem.
                    </h1>

                    <p class="mt-7 max-w-2xl text-base leading-8 text-slate-300 sm:text-lg">
                        Sustav za izradu, provjeru, auditiranje i potpisivanje dokumenata kroz jasno definirane tokove:
                        korisnici, kupci, vozila, predlošci, PDF dokumenti, certifikati, hash vrijednosti i audit događaji.
                    </p>

                    <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                        <a href="#workflow" class="inline-flex items-center justify-center rounded-full bg-cyan-300 px-6 py-3 text-sm font-bold text-slate-950 shadow-2xl shadow-cyan-950/30 transition hover:bg-cyan-200">
                            Pregled workflowa
                            <svg viewBox="0 0 20 20" aria-hidden="true" class="ml-2 h-4 w-4">
                                <path fill="currentColor" d="M12.293 4.293a1 1 0 0 1 1.414 0l5 5a1 1 0 0 1 0 1.414l-5 5a1 1 0 1 1-1.414-1.414L15.586 11H2a1 1 0 1 1 0-2h13.586l-3.293-3.293a1 1 0 0 1 0-1.414Z"/>
                            </svg>
                        </a>
                        <a href="#security" class="inline-flex items-center justify-center rounded-full border border-white/10 bg-white/5 px-6 py-3 text-sm font-bold text-white transition hover:border-white/20 hover:bg-white/10">
                            Sigurnosni sloj
                        </a>
                    </div>

                    <dl class="mt-12 grid max-w-2xl grid-cols-3 gap-3">
                        <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                            <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Audit</dt>
                            <dd class="mt-2 text-2xl font-semibold text-white">100%</dd>
                        </div>
                        <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                            <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">Hash</dt>
                            <dd class="mt-2 text-2xl font-semibold text-white">SHA-256</dd>
                        </div>
                        <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                            <dt class="text-xs uppercase tracking-[0.22em] text-slate-500">DB</dt>
                            <dd class="mt-2 text-2xl font-semibold text-white">JSONB</dd>
                        </div>
                    </dl>
                </div>

                <div class="relative" id="demo">
                    <div class="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-cyan-300/20 via-indigo-400/10 to-emerald-300/20 blur-2xl"></div>

                    <div class="relative overflow-hidden rounded-[2rem] border border-white/10 bg-slate-900/80 shadow-2xl shadow-slate-950/60 backdrop-blur">
                        <div class="border-b border-white/10 px-5 py-4">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-white">Ugovor #DS-2026-001</p>
                                    <p class="mt-1 text-xs text-slate-400">Kupoprodajni ugovor · PDF priprema</p>
                                </div>
                                <span class="rounded-full bg-amber-300/10 px-3 py-1 text-xs font-semibold text-amber-200 ring-1 ring-amber-300/20">
                                    pending_signatures
                                </span>
                            </div>
                        </div>

                        <div class="p-5">
                            <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.24em] text-slate-500">Dokument</p>
                                        <h2 class="mt-2 text-xl font-semibold text-white">Vehicle Sales Agreement</h2>
                                    </div>
                                    <div class="rounded-2xl bg-cyan-300/10 p-3 text-cyan-200 ring-1 ring-cyan-300/20">
                                        <svg viewBox="0 0 24 24" class="h-6 w-6" aria-hidden="true">
                                            <path fill="currentColor" d="M6 2.75A2.25 2.25 0 0 0 3.75 5v14A2.25 2.25 0 0 0 6 21.25h12A2.25 2.25 0 0 0 20.25 19V8.31a2.25 2.25 0 0 0-.66-1.59l-3.31-3.31a2.25 2.25 0 0 0-1.59-.66H6Zm8 1.5v3A1.75 1.75 0 0 0 15.75 9h3v10A.75.75 0 0 1 18 19.75H6A.75.75 0 0 1 5.25 19V5A.75.75 0 0 1 6 4.25h8Zm1.5.31L17.94 7.5h-2.19a.25.25 0 0 1-.25-.25V4.56Z"/>
                                        </svg>
                                    </div>
                                </div>

                                <div class="mt-6 space-y-3">
                                    <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 px-4 py-3">
                                        <span class="text-sm text-slate-400">Kupac</span>
                                        <span class="text-sm font-medium text-white">Mateo Ljevar</span>
                                    </div>
                                    <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 px-4 py-3">
                                        <span class="text-sm text-slate-400">Vozilo</span>
                                        <span class="text-sm font-medium text-white">VW Polo 6R</span>
                                    </div>
                                    <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 px-4 py-3">
                                        <span class="text-sm text-slate-400">Integritet dokumenta</span>
                                        <span class="text-sm font-medium text-emerald-200">SHA-256 validiran</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                                    <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Potpisnik</p>
                                    <p class="mt-2 text-sm font-medium text-white">Kupac</p>
                                    <div class="mt-4 h-2 rounded-full bg-slate-800">
                                        <div class="h-2 w-2/3 rounded-full bg-cyan-300"></div>
                                    </div>
                                    <p class="mt-3 text-xs text-slate-400">Token aktivan · čeka potvrdu</p>
                                </div>

                                <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                                    <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Audit</p>
                                    <p class="mt-2 text-sm font-medium text-white">5 događaja</p>
                                    <div class="mt-4 flex -space-x-2">
                                        <span class="h-8 w-8 rounded-full border border-slate-900 bg-emerald-300"></span>
                                        <span class="h-8 w-8 rounded-full border border-slate-900 bg-cyan-300"></span>
                                        <span class="h-8 w-8 rounded-full border border-slate-900 bg-indigo-300"></span>
                                    </div>
                                    <p class="mt-3 text-xs text-slate-400">Trace log spreman</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="platform" class="px-5 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">Platforma</p>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                        Modularna struktura za stvaran poslovni proces, ne samo demonstraciju.
                    </h2>
                    <p class="mt-5 text-base leading-8 text-slate-300">
                        Sučelje je zamišljeno oko stvarnih entiteta iz baze: ugovor, predložak, vozilo, kupac, dokument,
                        certifikat, potpis i audit zapis. Svaki modul ima jasnu odgovornost.
                    </p>
                </div>

                <div class="mt-10 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <article class="group rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-6 transition hover:-translate-y-1 hover:border-cyan-300/30 hover:bg-cyan-300/[0.06]">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-cyan-300/10 text-cyan-200 ring-1 ring-cyan-300/20">
                            <svg viewBox="0 0 24 24" class="h-6 w-6" aria-hidden="true">
                                <path fill="currentColor" d="M5 4.75A2.75 2.75 0 0 1 7.75 2h8.5A2.75 2.75 0 0 1 19 4.75v14.5A2.75 2.75 0 0 1 16.25 22h-8.5A2.75 2.75 0 0 1 5 19.25V4.75ZM7.75 3.5c-.69 0-1.25.56-1.25 1.25v14.5c0 .69.56 1.25 1.25 1.25h8.5c.69 0 1.25-.56 1.25-1.25V4.75c0-.69-.56-1.25-1.25-1.25h-8.5Z"/>
                            </svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-white">Predlošci ugovora</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Verzije, engine, lokalizacija i JSONB schema polja za dinamičko popunjavanje dokumenata.
                        </p>
                    </article>

                    <article class="group rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-6 transition hover:-translate-y-1 hover:border-emerald-300/30 hover:bg-emerald-300/[0.06]">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-300/10 text-emerald-200 ring-1 ring-emerald-300/20">
                            <svg viewBox="0 0 24 24" class="h-6 w-6" aria-hidden="true">
                                <path fill="currentColor" d="M12 12.75A4.75 4.75 0 1 0 12 3.25a4.75 4.75 0 0 0 0 9.5Zm0-8A3.25 3.25 0 1 1 12 11.25a3.25 3.25 0 0 1 0-6.5ZM4.25 20.5c.73-3.42 3.84-6 7.75-6s7.02 2.58 7.75 6H18.2c-.68-2.55-3.1-4.5-6.2-4.5s-5.52 1.95-6.2 4.5H4.25Z"/>
                           .52 1.95-6.2 4.5H4 </svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-white">Kupci i identitet</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Fizičke i pravne osobe, OIB provjere, identity capture i provjera izvora podataka.
                        </p>
                    </article>

                    <article class="group rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-6 transition hover:-translate-y-1 hover:border-indigo-300/30 hover:bg-indigo-300/[0.06]">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-300/10 text-indigo-200 ring-1 ring-indigo-300/20">
                            <svg viewBox="0 0 24 24" class="h-6 w-6" aria-hidden="true">
                                <path fill="currentColor" d="M4.5 9.5 6.28 5.2A2.75 2.75 0 0 1 8.82 3.5h6.36a2.75 2.75 0 0 1 2.54 1.7L19.5 9.5A2.5 2.5 0 0 1 21 11.8v5.45a1.25 1.25 0 0 1-1.25 1.25H18.5A1.5 1.5 0 0 1 17 17h-10a1.5 1.5 0 0 1-1.5 1.5H4.25A1.25 1.25 0 0 1 3 17.25V11.8a2.5 2.5 0 0 1 1.5-2.3Zm3.16-3.72L6.1 9.5h11.8l-1.56-3.72A1.25 1.25 0 0 0 15.18 5H8.82c-.5 0-.95.3-1.16.78ZM5.5 11A1 1 0 0 0 4.5 12v5h1.25v-1.5h12.5V17h1.25v-5a1 1 0 0 0-1-1h-13Zm1.75 2.25h2v1.5h-2v-1.5Zm7.5 0h2v1.5h-2v-1.5Z"/>
                            </svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-white">Vozila i atributi</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            VIN, registracija, tehnički podaci i fleksibilni JSONB atributi za dodatna svojstva.
                        </p>
                    </article>

                    <article class="group rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-6 transition hover:-translate-y-1 hover:border-amber-300/30 hover:bg-amber-300/[0.06]">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-300/10 text-amber-200 ring-1 ring-amber-300/20">
                            <svg viewBox="0 0 24 24" class="h-6 w-6" aria-hidden="true">
                                <path fill="currentColor" d="M12 1.75 4.75 4.8v5.05c0 4.62 3.07 8.92 7.25 10.4 4.18-1.48 7.25-5.78 7.25-10.4V4.8L12 1.75Zm0 1.62 5.75 2.42v4.06c0 3.78-2.38 7.3-5.75 8.8-3.37-1.5-5.75-5.02-5.75-8.8V5.79L12 3.37Zm2.7 5.34 1.06 1.06-4.57 4.57-2.45-2.45 1.06-1.06 1.39 1.39 3.51-3.51Z"/>
                            </svg>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-white">Digitalni potpis</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Certifikati, hash prije/poslije potpisa, status potpisa i dokaziv audit trag.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section id="workflow" class="px-5 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl rounded-[2rem] border border-white/10 bg-white/[0.04] p-6 sm:p-8 lg:p-10">
                <div class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.28em] text-emerald-200">Workflow</p>
                        <h2 class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                            Od podataka do potpisanog PDF-a.
                        </h2>
                        <p class="mt-5 text-base leading-8 text-slate-300">
                            Proces je složen tako da svaki korak ostavlja tehnički trag: snapshot podataka, hash dokumenta,
                            status potpisa i audit zapis.
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @php
                            $steps = [
                                ['01', 'Unos podataka', 'Kupac, vozilo i osnovni podaci ugovora ulaze u strukturirane tablice.'],
                                ['02', 'Snapshot ugovora', 'JSONB snapshot zaključava podatke koji su korišteni za dokument.'],
                                ['03', 'Generiranje dokumenta', 'Predložak proizvodi DOCX/PDF zapis i računa se SHA-256 hash.'],
                                ['04', 'Digitalni potpis', 'Potpis se veže uz certifikat, hash i vrijeme potpisivanja.'],
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

        <section id="security" class="px-5 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-3">
                <div class="lg:col-span-1">
                    <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">Sigurnost</p>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                        Projektiran za integritet i provjerljivost.
                    </h2>
                </div>

                <div class="grid gap-4 lg:col-span-2 sm:grid-cols-2">
                    <div class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">Private storage</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Datoteke se vode kroz centralnu tablicu i nisu zamišljene kao javni asseti.
                        </p>
                    </div>
                    <div class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">SHA-256 integritet</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Dokumenti imaju hash vrijednosti prije i nakon završnog potpisivanja.
                        </p>
                    </div>
                    <div class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">Token hash</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Pristupni token se ne sprema kao plain vrijednost, nego kao hash.
                        </p>
                    </div>
                    <div class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-6">
                        <h3 class="text-lg font-semibold text-white">Audit događaji</h3>
                        <p class="mt-3 text-sm leading-6 text-slate-400">
                            Ključne akcije ostavljaju zapis: tko, kada, nad kojim entitetom i s kojim rezultatom.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section id="data-model" class="px-5 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl overflow-hidden rounded-[2rem] border border-white/10 bg-slate-900/70 shadow-2xl shadow-slate-950/40">
                <div class="border-b border-white/10 p-6 sm:p-8">
                    <p class="text-sm font-semibold uppercase tracking-[0.28em] text-emerald-200">PostgreSQL model</p>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                        Relacijski model s JSONB fleksibilnošću.
                    </h2>
                    <p class="mt-5 max-w-3xl text-base leading-8 text-slate-300">
                        Glavni poslovni podaci ostaju u normaliziranim kolonama, dok se varijabilni podaci i snapshotovi drže u JSONB poljima.
                    </p>
                </div>

                <div class="grid divide-y divide-white/10 md:grid-cols-3 md:divide-x md:divide-y-0">
                    <div class="p-6 sm:p-8">
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Core</p>
                        <ul class="mt-5 space-y-3 text-sm text-slate-300">
                            <li>users</li>
                            <li>roles</li>
                            <li>customers</li>
                            <li>vehicles</li>
                        </ul>
                    </div>
                    <div class="p-6 sm:p-8">
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Documents</p>
                        <ul class="mt-5 space-y-3 text-sm text-slate-300">
                            <li>contract_templates</li>
                            <li>contracts</li>
                            <li>contract_documents</li>
                            <li>files</li>
                        </ul>
                    </div>
                    <div class="p-6 sm:p-8">
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Trust layer</p>
                        <ul class="mt-5 space-y-3 text-sm text-slate-300">
                            <li>certificates</li>
                            <li>signatures</li>
                            <li>contract_access_tokens</li>
                            <li>audit_events</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/10 px-5 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ now()->year }} Digital Sign Master Degree. Lokalni akademski projekt.</p>
            <p class="text-slate-400">Laravel · PostgreSQL · Tailwind CSS · Audit-first architecture</p>
        </div>
    </footer>
</body>
</html>