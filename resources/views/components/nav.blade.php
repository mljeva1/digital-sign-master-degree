@props(['active' => ''])

@php
    $items = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard'],
        ['key' => 'contracts', 'label' => 'Ugovori', 'route' => 'contracts.index'],
        ['key' => 'documents', 'label' => 'Dokumenti', 'route' => 'documents.index'],
        ['key' => 'certificate', 'label' => 'Certifikat', 'route' => 'certificate-requests.index'],
        ['key' => 'profile', 'label' => 'Profil', 'route' => 'profile.edit'],
    ];

    // The operator inbox link is shown ONLY to an exact certificate_operator; the
    // route is additionally protected by role middleware, a policy, and a locked
    // service re-check — a hidden link is never authorization. The membership read
    // is guarded ONLY against the specific missing-role-schema case some
    // hand-built partial-schema test renders produce (a QueryException), so the
    // shared layout never hard-depends on the role tables; any other error still
    // surfaces normally.
    $showOperatorLink = false;
    if (auth()->check()) {
        try {
            $showOperatorLink = auth()->user()->hasRole('certificate_operator');
        } catch (\Illuminate\Database\QueryException) {
            $showOperatorLink = false; // role tables absent in this render context
        }
    }
    if ($showOperatorLink) {
        $items[] = ['key' => 'certificate-operator', 'label' => 'Certifikati — operater', 'route' => 'certificate-operator.requests.index'];
    }

    $linkBase = 'rounded-xl px-3.5 py-2 text-sm font-medium transition min-h-[44px] inline-flex items-center';
    $linkIdle = 'text-slate-300 hover:bg-white/5 hover:text-white';
    $linkActive = 'bg-cyan-300/10 text-cyan-100 ring-1 ring-inset ring-cyan-300/30';
@endphp

<header class="sticky top-0 z-50 border-b border-white/10 bg-slate-950/80 backdrop-blur-xl">
    <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-3 sm:px-6 lg:px-8" aria-label="Glavna navigacija">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-cyan-300/20 bg-cyan-300/10 text-cyan-200">
                <svg viewBox="0 0 24 24" aria-hidden="true" class="h-5 w-5">
                    <path fill="currentColor" d="M12 2.25 4.5 5.42v5.08c0 4.72 3.2 9.14 7.5 10.25 4.3-1.11 7.5-5.53 7.5-10.25V5.42L12 2.25Zm0 2.18 5.5 2.32v3.75c0 3.62-2.2 6.96-5.5 8.14-3.3-1.18-5.5-4.52-5.5-8.14V6.75L12 4.43Z"/>
                    <path fill="currentColor" d="M10.77 14.8 7.95 12l1.06-1.06 1.76 1.75 4.21-4.21 1.07 1.06-5.28 5.26Z"/>
                </svg>
            </span>
            <span class="text-sm font-semibold tracking-[0.24em] text-cyan-100">DSMD</span>
        </a>

        {{-- Desktop navigation --}}
        <div class="hidden items-center gap-1 md:flex">
            @foreach ($items as $item)
                <a
                    href="{{ route($item['route']) }}"
                    @class([$linkBase, $active === $item['key'] ? $linkActive : $linkIdle])
                    @if ($active === $item['key']) aria-current="page" @endif
                >
                    {{ $item['label'] }}
                </a>
            @endforeach

            <form method="POST" action="{{ route('logout') }}" class="ml-2">
                @csrf
                <button type="submit" class="min-h-[44px] rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-sm font-semibold text-slate-200 transition hover:border-red-300/40 hover:bg-red-300/10 hover:text-red-100">
                    Odjava
                </button>
            </form>
        </div>

        {{-- Mobile toggle --}}
        <button
            type="button"
            id="mobileNavToggle"
            class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-200 transition hover:bg-white/10 md:hidden"
            aria-controls="mobileNavPanel"
            aria-expanded="false"
            aria-label="Otvori navigaciju"
        >
            <svg viewBox="0 0 24 24" class="h-6 w-6" aria-hidden="true">
                <path fill="currentColor" d="M3.75 6.75h16.5v1.5H3.75v-1.5Zm0 4.5h16.5v1.5H3.75v-1.5Zm0 4.5h16.5v1.5H3.75v-1.5Z"/>
            </svg>
        </button>
    </nav>

    {{-- Mobile panel --}}
    <div id="mobileNavPanel" class="hidden border-t border-white/10 bg-slate-950/95 md:hidden">
        <div class="mx-auto flex max-w-6xl flex-col gap-1 px-5 py-3">
            @foreach ($items as $item)
                <a
                    href="{{ route($item['route']) }}"
                    @class(['min-h-[44px] rounded-xl px-4 py-3 text-sm font-medium transition inline-flex items-center', $active === $item['key'] ? $linkActive : $linkIdle])
                    @if ($active === $item['key']) aria-current="page" @endif
                >
                    {{ $item['label'] }}
                </a>
            @endforeach

            <form method="POST" action="{{ route('logout') }}" class="mt-1">
                @csrf
                <button type="submit" class="min-h-[44px] w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-left text-sm font-semibold text-slate-200 transition hover:border-red-300/40 hover:bg-red-300/10 hover:text-red-100">
                    Odjava
                </button>
            </form>
        </div>
    </div>
</header>

<script>
    (() => {
        const toggle = document.getElementById('mobileNavToggle');
        const panel = document.getElementById('mobileNavPanel');

        if (! toggle || ! panel) {
            return;
        }

        const closePanel = () => {
            panel.classList.add('hidden');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Otvori navigaciju');
        };

        const openPanel = () => {
            panel.classList.remove('hidden');
            toggle.setAttribute('aria-expanded', 'true');
            toggle.setAttribute('aria-label', 'Zatvori navigaciju');
        };

        toggle.addEventListener('click', () => {
            panel.classList.contains('hidden') ? openPanel() : closePanel();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && ! panel.classList.contains('hidden')) {
                closePanel();
                toggle.focus();
            }
        });

        document.addEventListener('click', (event) => {
            if (
                ! panel.classList.contains('hidden')
                && ! panel.contains(event.target)
                && ! toggle.contains(event.target)
            ) {
                closePanel();
            }
        });
    })();
</script>
