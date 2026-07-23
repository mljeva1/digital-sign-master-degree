@php
    use App\Support\CertificateRequests\CertificateRequestPresenter as Presenter;
@endphp

<x-app-layout title="Certifikati — operater" active="certificate-operator">
    <x-page-header
        title="Zahtjevi za certifikate"
        subtitle="Pregled i odluka o zahtjevima korisnika za lokalni akademski certifikat." />

    <x-flash />

    {{-- Allow-listed status filter --}}
    <form method="GET" action="{{ route('certificate-operator.requests.index') }}" class="mt-6 flex flex-wrap items-center gap-2">
        <label for="status" class="text-sm text-slate-400">Status:</label>
        <select
            id="status"
            name="status"
            class="rounded-xl border border-white/10 bg-slate-900/60 px-3 py-2 text-sm text-slate-100"
            onchange="this.form.submit()">
            <option value="">Svi</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($statusFilter === $status)>{{ Presenter::statusLabel($status) }}</option>
            @endforeach
        </select>
        <noscript><x-action type="submit" variant="secondary" size="sm">Filtriraj</x-action></noscript>
    </form>

    <div class="mt-6">
        @if ($requests->total() === 0)
            <x-empty-state title="Nema zahtjeva" description="Za odabrani filter trenutačno nema zahtjeva." />
        @else
            <x-card padding="p-0">
                <ul class="divide-y divide-white/5">
                    @foreach ($requests as $item)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <a href="{{ route('certificate-operator.requests.show', $item) }}" class="text-sm font-semibold text-white hover:text-cyan-200">
                                    Zahtjev #{{ $item->id }}
                                </a>
                                <p class="text-xs text-slate-500">
                                    Korisnik #{{ $item->user_id }} · {{ optional($item->created_at)->format('d.m.Y. H:i') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <x-badge :tone="Presenter::statusTone($item->status)">{{ Presenter::statusLabel($item->status) }}</x-badge>
                                <x-action :href="route('certificate-operator.requests.show', $item)" variant="ghost" size="sm">Otvori</x-action>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>
            <div class="mt-4">{{ $requests->links() }}</div>
        @endif
    </div>
</x-app-layout>
