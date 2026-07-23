@php
    use App\Support\CertificateRequests\CertificateRequestPresenter as Presenter;
@endphp

<x-app-layout title="Certifikat" active="certificate">
    <x-page-header
        title="Certifikat za potpisivanje"
        subtitle="Zatraži lokalni akademski X.509 certifikat kojim aplikacija potpisuje tvoje dokumente." />

    <x-flash />

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            {{-- Active / most recent certificate --}}
            @if ($activeCertificate)
                <x-card>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Tvoj certifikat</h2>
                            <p class="mt-1 text-sm text-slate-400">Certifikat #{{ $activeCertificate->id }}</p>
                        </div>
                        @if ($activeCertificate->isCurrentlyValid())
                            <x-badge tone="emerald">Aktivan i važeći</x-badge>
                        @elseif ($activeCertificate->is_active)
                            <x-badge tone="amber">Istekao</x-badge>
                        @else
                            <x-badge tone="slate">Neaktivan</x-badge>
                        @endif
                    </div>

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                        <div>
                            <dt class="text-slate-400">Vrijedi od</dt>
                            <dd class="text-slate-100">{{ optional($activeCertificate->valid_from)->format('d.m.Y.') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">Vrijedi do</dt>
                            <dd class="text-slate-100">{{ optional($activeCertificate->valid_to)->format('d.m.Y.') ?? '—' }}</dd>
                        </div>
                    </dl>
                </x-card>
            @endif

            {{-- Create --}}
            @if ($canCreate)
                <x-card>
                    <h2 class="text-lg font-semibold text-white">Novi zahtjev</h2>
                    <p class="mt-1 text-sm text-slate-400">Operater pregledava zahtjev prije izdavanja certifikata.</p>

                    <form method="POST" action="{{ route('certificate-requests.store') }}" class="mt-4 space-y-4" data-single-submit>
                        @csrf
                        <div>
                            <label for="request_note" class="block text-sm font-medium text-slate-200">Napomena (nije obavezno)</label>
                            <textarea
                                id="request_note"
                                name="request_note"
                                rows="3"
                                maxlength="1000"
                                class="mt-1 w-full rounded-xl border border-white/10 bg-slate-900/60 px-3.5 py-2.5 text-sm text-slate-100 placeholder:text-slate-500"
                                placeholder="npr. razlog ili kontekst zahtjeva">{{ old('request_note') }}</textarea>
                        </div>
                        <x-action type="submit" variant="primary">Pošalji zahtjev</x-action>
                    </form>
                </x-card>
            @elseif ($activeRequest)
                <x-card>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Zahtjev u obradi</h2>
                            <p class="mt-1 text-sm text-slate-400">Zahtjev #{{ $activeRequest->id }} je trenutačno aktivan.</p>
                        </div>
                        <x-badge :tone="Presenter::statusTone($activeRequest->status)">{{ Presenter::statusLabel($activeRequest->status) }}</x-badge>
                    </div>
                    <div class="mt-4">
                        <x-action :href="route('certificate-requests.show', $activeRequest)" variant="secondary" size="sm">Detalji</x-action>
                    </div>
                </x-card>
            @elseif ($hasBlockingCertificate)
                <x-card>
                    <p class="text-sm text-slate-300">Već imaš aktivan važeći certifikat, pa novi zahtjev trenutačno nije moguć.</p>
                </x-card>
            @endif

            {{-- History --}}
            <div>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">Povijest zahtjeva</h2>
                @if ($requests->total() === 0)
                    <x-empty-state title="Još nema zahtjeva" description="Kada pošalješ zahtjev, ovdje ćeš pratiti njegov status." />
                @else
                    <x-card padding="p-0">
                        <ul class="divide-y divide-white/5">
                            @foreach ($requests as $item)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                    <div class="min-w-0">
                                        <a href="{{ route('certificate-requests.show', $item) }}" class="text-sm font-semibold text-white hover:text-cyan-200">
                                            Zahtjev #{{ $item->id }}
                                        </a>
                                        <p class="text-xs text-slate-500">{{ optional($item->created_at)->format('d.m.Y. H:i') }}</p>
                                    </div>
                                    <x-badge :tone="Presenter::statusTone($item->status)">{{ Presenter::statusLabel($item->status) }}</x-badge>
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                    <div class="mt-4">{{ $requests->links() }}</div>
                @endif
            </div>
        </div>

        {{-- Security notices --}}
        <aside class="space-y-4">
            <x-card>
                <h2 class="text-sm font-semibold text-white">Važno o certifikatu</h2>
                <ul class="mt-3 space-y-3 text-sm text-slate-300">
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-cyan-300">•</span>
                        <span>Privatni ključ ostaje na poslužitelju — <strong class="text-white">ne dobivaš privatni ključ</strong>.</span>
                    </li>
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-cyan-300">•</span>
                        <span>Ovo je lokalna akademska demonstracija. Certifikat i potpis <strong class="text-white">nemaju pravnu valjanost</strong> ni svojstvo neporecivosti (non-repudiation).</span>
                    </li>
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="text-cyan-300">•</span>
                        <span>Nije riječ o kvalificiranom certifikatu, eIDAS/QES-u ni produkcijskom CA.</span>
                    </li>
                </ul>
            </x-card>
        </aside>
    </div>

    <script>
        document.querySelectorAll('form[data-single-submit]').forEach((form) => {
            form.addEventListener('submit', () => {
                form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; });
            });
        });
    </script>
</x-app-layout>
