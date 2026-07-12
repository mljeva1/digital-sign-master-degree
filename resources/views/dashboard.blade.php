<x-app-layout title="Dashboard" active="dashboard" max-width="max-w-5xl">
    <x-page-header
        title="Dashboard"
        subtitle="Pregled i brzi ulaz u izradu, provjeru i upravljanje dokumentima."
    />

    <x-flash />

    <div class="mt-8 grid gap-5 lg:grid-cols-3">
        <x-card class="lg:col-span-2">
            <p class="text-xs uppercase tracking-[0.24em] text-slate-500">Prijavljeni korisnik</p>
            <h2 class="mt-2 text-2xl font-semibold text-white">{{ $user->name }}</h2>
            <p class="mt-1 text-sm text-slate-400">{{ $user->email }}</p>

            <dl class="mt-6 grid max-w-md grid-cols-2 gap-3">
                <div class="rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.06] px-4 py-3">
                    <dt class="text-xs uppercase tracking-[0.2em] text-cyan-200/80">Draft ugovori</dt>
                    <dd class="mt-1 text-2xl font-semibold text-white">{{ $draftCount }}</dd>
                </div>
                <div class="rounded-2xl border border-emerald-300/15 bg-emerald-300/[0.06] px-4 py-3">
                    <dt class="text-xs uppercase tracking-[0.2em] text-emerald-200/80">Finalizirani</dt>
                    <dd class="mt-1 text-2xl font-semibold text-white">{{ $finalizedCount }}</dd>
                </div>
            </dl>

            @if ($draftCount === 0 && $finalizedCount === 0)
                <p class="mt-3 text-sm text-slate-400">
                    Još nemaš spremljenih ugovora — započni prvi kroz builder s live pregledom.
                </p>
            @endif

            <div class="mt-6 flex flex-wrap gap-3">
                <x-action href="{{ route('contracts.create') }}" variant="primary">Novi ugovor</x-action>
                <x-action href="{{ route('contracts.index') }}" variant="secondary">Moji ugovori</x-action>
                <x-action href="{{ route('documents.index') }}" variant="secondary">Dokumenti</x-action>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs uppercase tracking-[0.24em] text-slate-500">Profil za autofill</p>
                @if ($profileReady)
                    <x-badge tone="emerald">Spremno</x-badge>
                @else
                    <x-badge tone="amber">Nepotpun</x-badge>
                @endif
            </div>

            @if ($profileReady)
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    Tvoj profil ima ime, OIB i adresu potrebne da builder ponudi automatsko
                    popunjavanje prodavatelja ili kupca.
                </p>
            @else
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    Dopuni ime, prezime, OIB i adresu (ulica, poštanski broj i grad) da builder
                    može ponuditi automatsko popunjavanje ugovorne strane.
                </p>
            @endif

            <a href="{{ route('profile.edit') }}" class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-cyan-200 transition hover:text-cyan-100">
                Uredi profil
                <svg viewBox="0 0 20 20" class="h-4 w-4" aria-hidden="true">
                    <path fill="currentColor" d="M7.3 15.7a1 1 0 0 1 0-1.4L11.58 10 7.3 5.7a1 1 0 1 1 1.4-1.4l5 5a1 1 0 0 1 0 1.4l-5 5a1 1 0 0 1-1.4 0Z"/>
                </svg>
            </a>
        </x-card>
    </div>

    <div class="mt-5 grid gap-5 sm:grid-cols-3">
        <a href="{{ route('contracts.create') }}" class="group rounded-3xl border border-cyan-300/20 bg-cyan-300/[0.06] p-5 transition hover:border-cyan-300/40 hover:bg-cyan-300/10">
            <h3 class="text-base font-semibold text-cyan-100">Izradi ugovor</h3>
            <p class="mt-2 text-sm leading-6 text-cyan-100/70">
                Kupoprodajni ugovor kroz formu s live pregledom.
            </p>
        </a>

        <a href="{{ route('contracts.index') }}" class="group rounded-3xl border border-white/10 bg-white/[0.04] p-5 transition hover:border-white/20 hover:bg-white/[0.07]">
            <h3 class="text-base font-semibold text-white">Nastavi na ugovorima</h3>
            <p class="mt-2 text-sm leading-6 text-slate-400">
                Draftovi, finalizacija, probni i finalni PDF te javna provjera.
            </p>
        </a>

        <a href="{{ route('documents.index') }}" class="group rounded-3xl border border-white/10 bg-white/[0.04] p-5 transition hover:border-white/20 hover:bg-white/[0.07]">
            <h3 class="text-base font-semibold text-white">Dokumenti</h3>
            <p class="mt-2 text-sm leading-6 text-slate-400">
                Privatni upload uz SHA-256 provjeru integriteta.
            </p>
        </a>
    </div>
</x-app-layout>
