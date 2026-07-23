@php
    use App\Support\CertificateRequests\CertificateRequestPresenter as Presenter;
    $failureMessage = Presenter::failureMessage($request->failure_code);
@endphp

<x-app-layout title="Zahtjev #{{ $request->id }}" active="certificate">
    <x-page-header
        title="Zahtjev #{{ $request->id }}"
        :back="route('certificate-requests.index')"
        backLabel="Natrag na certifikate">
        <x-slot:actions>
            <x-badge :tone="Presenter::statusTone($request->status)">{{ Presenter::statusLabel($request->status) }}</x-badge>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <x-card>
                <h2 class="text-lg font-semibold text-white">Status zahtjeva</h2>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                    <div>
                        <dt class="text-slate-400">Podnesen</dt>
                        <dd class="text-slate-100">{{ optional($request->created_at)->format('d.m.Y. H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Pregledan</dt>
                        <dd class="text-slate-100">{{ optional($request->reviewed_at)->format('d.m.Y. H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Izdan</dt>
                        <dd class="text-slate-100">{{ optional($request->issued_at)->format('d.m.Y. H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Napomena operatera</dt>
                        <dd class="text-slate-100">{{ $request->operator_note ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($failureMessage)
                    <div class="mt-4 rounded-2xl border border-red-300/20 bg-red-300/10 px-4 py-3 text-sm text-red-100" role="alert">
                        {{ $failureMessage }}
                    </div>
                @endif
            </x-card>

            @if ($certificate)
                <x-card>
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-white">Izdani certifikat</h2>
                        @if ($certificate->isCurrentlyValid())
                            <x-badge tone="emerald">Aktivan i važeći</x-badge>
                        @else
                            <x-badge tone="amber">Nije važeći</x-badge>
                        @endif
                    </div>
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                        <div>
                            <dt class="text-slate-400">Certifikat</dt>
                            <dd class="text-slate-100">#{{ $certificate->id }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">Vrijedi</dt>
                            <dd class="text-slate-100">
                                {{ optional($certificate->valid_from)->format('d.m.Y.') ?? '—' }} – {{ optional($certificate->valid_to)->format('d.m.Y.') ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </x-card>
            @endif

            @if ($request->isPending())
                <x-card>
                    <h2 class="text-lg font-semibold text-white">Otkazivanje</h2>
                    <p class="mt-1 text-sm text-slate-400">Dok je zahtjev na čekanju, možeš ga otkazati.</p>
                    <form
                        method="POST"
                        action="{{ route('certificate-requests.cancel', $request) }}"
                        class="mt-4"
                        data-single-submit
                        onsubmit="return confirm('Otkazati ovaj zahtjev?');">
                        @csrf
                        @method('PATCH')
                        <x-action type="submit" variant="danger">Otkaži zahtjev</x-action>
                    </form>
                </x-card>
            @endif
        </div>

        <aside class="space-y-4">
            <x-card>
                <h2 class="text-sm font-semibold text-white">Napomena</h2>
                <p class="mt-3 text-sm text-slate-300">Ne dobivaš privatni ključ. Certifikat i potpis nemaju pravnu valjanost ni neporecivost.</p>
            </x-card>
        </aside>
    </div>

    <script>
        document.querySelectorAll('form[data-single-submit]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (event.defaultPrevented) { return; }
                form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; });
            });
        });
    </script>
</x-app-layout>
