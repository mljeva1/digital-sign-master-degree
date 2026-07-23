@php
    use App\Support\CertificateRequests\CertificateRequestPresenter as Presenter;
    $failureMessage = Presenter::failureMessage($request->failure_code);
@endphp

<x-app-layout title="Zahtjev #{{ $request->id }} — operater" active="certificate-operator">
    <x-page-header
        title="Zahtjev #{{ $request->id }}"
        :back="route('certificate-operator.requests.index')"
        backLabel="Natrag na zahtjeve">
        <x-slot:actions>
            <x-badge :tone="Presenter::statusTone($request->status)">{{ Presenter::statusLabel($request->status) }}</x-badge>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <x-card>
                <h2 class="text-lg font-semibold text-white">Podaci za odluku</h2>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                    <div>
                        <dt class="text-slate-400">Podnositelj</dt>
                        <dd class="text-slate-100">Korisnik #{{ $request->user_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Podnesen</dt>
                        <dd class="text-slate-100">{{ optional($request->created_at)->format('d.m.Y. H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Napomena korisnika</dt>
                        <dd class="text-slate-100">{{ filled($request->request_note) ? 'priložena' : 'nema' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Pregledao</dt>
                        <dd class="text-slate-100">{{ $request->reviewed_by_user_id ? 'Operater #'.$request->reviewed_by_user_id : '—' }}</dd>
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
        </div>

        {{-- Review actions --}}
        <aside class="space-y-6">
            @if ($canReview)
                <x-card>
                    <h2 class="text-lg font-semibold text-white">Odobri</h2>
                    <p class="mt-1 text-sm text-slate-400">Odobravanje atomski predaje zahtjev na izdavanje.</p>
                    <form
                        method="POST"
                        action="{{ route('certificate-operator.requests.approve', $request) }}"
                        class="mt-4"
                        data-single-submit
                        onsubmit="return confirm('Odobriti ovaj zahtjev i pokrenuti izdavanje?');">
                        @csrf
                        <x-action type="submit" variant="success">Odobri zahtjev</x-action>
                    </form>
                </x-card>

                <x-card>
                    <h2 class="text-lg font-semibold text-white">Odbij</h2>
                    <form
                        method="POST"
                        action="{{ route('certificate-operator.requests.reject', $request) }}"
                        class="mt-4 space-y-3"
                        data-single-submit>
                        @csrf
                        <div>
                            <label for="operator_note" class="block text-sm font-medium text-slate-200">Obrazloženje (obavezno)</label>
                            <textarea
                                id="operator_note"
                                name="operator_note"
                                rows="3"
                                required
                                minlength="3"
                                maxlength="1000"
                                class="mt-1 w-full rounded-xl border border-white/10 bg-slate-900/60 px-3.5 py-2.5 text-sm text-slate-100 placeholder:text-slate-500"
                                placeholder="Razlog odbijanja">{{ old('operator_note') }}</textarea>
                            @error('operator_note')
                                <p class="mt-1 text-sm text-red-200" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <x-action type="submit" variant="danger">Odbij zahtjev</x-action>
                    </form>
                </x-card>
            @else
                <x-card>
                    <p class="text-sm text-slate-300">Ovaj zahtjev nije moguće pregledati (nije na čekanju ili je tvoj vlastiti).</p>
                </x-card>
            @endif
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
