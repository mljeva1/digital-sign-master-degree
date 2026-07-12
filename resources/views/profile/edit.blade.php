@php
    $autofillFields = [
        $profile?->first_name,
        $profile?->last_name,
        $profile?->oib,
        $profile?->address_line1,
        $profile?->postal_code,
        $profile?->city,
    ];
    $profileReady = collect($autofillFields)->every(fn ($value) => filled($value));
@endphp

<x-app-layout title="Moj profil" active="profile" max-width="max-w-3xl">
    <x-page-header
        eyebrow="Moj profil"
        title="Podaci za kupoprodajni ugovor"
        subtitle="Sva su polja neobavezna i vezana samo uz tvoj račun. Spremljeni podaci mogu ubrzati izradu ugovora. Prazna polja ostaju prazna."
    />

    <x-flash />

    <div class="mt-6 flex items-start gap-3 rounded-2xl border px-4 py-4 {{ $profileReady ? 'border-emerald-300/20 bg-emerald-300/[0.07]' : 'border-amber-300/20 bg-amber-300/[0.07]' }}">
        <div class="mt-0.5">
            @if ($profileReady)
                <x-badge tone="emerald">Spremno za autofill</x-badge>
            @else
                <x-badge tone="amber">Profil nije potpun</x-badge>
            @endif
        </div>
        <div class="text-sm leading-6 {{ $profileReady ? 'text-emerald-100/90' : 'text-amber-100/90' }}">
            @if ($profileReady)
                U ugovor se prenose samo <strong>ime i prezime</strong>, <strong>adresa</strong> i <strong>OIB</strong>.
                Builder ti može ponuditi da ovim podacima popuniš prodavatelja ili kupca.
            @else
                Za automatsko popunjavanje ugovorne strane trebaju: ime, prezime, OIB te adresa
                (ulica, poštanski broj i grad). U ugovor se prenose samo ime i prezime, adresa i OIB.
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('profile.update') }}" class="mt-8 space-y-8">
        @csrf
        @method('PATCH')

        <x-card>
            <h2 class="text-base font-semibold text-white">Osobni podaci</h2>
            <p class="mt-1 text-sm text-slate-400">Ime, prezime i OIB — koriste se za automatsko popunjavanje ugovora.</p>

            <div class="mt-5 grid gap-6 sm:grid-cols-2">
                <div>
                    <label for="first_name" class="block text-sm font-medium text-slate-200">Ime</label>
                    <input id="first_name" name="first_name" type="text" maxlength="100" value="{{ old('first_name', $profile?->first_name) }}" autocomplete="given-name"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                    @error('first_name')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="last_name" class="block text-sm font-medium text-slate-200">Prezime</label>
                    <input id="last_name" name="last_name" type="text" maxlength="100" value="{{ old('last_name', $profile?->last_name) }}" autocomplete="family-name"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                    @error('last_name')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="oib" class="block text-sm font-medium text-slate-200">OIB</label>
                    <input id="oib" name="oib" type="text" inputmode="numeric" maxlength="11" value="{{ old('oib', $profile?->oib) }}" aria-describedby="oib_help"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                    <p id="oib_help" class="mt-2 text-xs text-slate-500">Točno 11 znamenki, bez razmaka. Neobavezno.</p>
                    @error('oib')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-card>

        <x-card>
            <h2 class="text-base font-semibold text-white">Adresa</h2>
            <p class="mt-1 text-sm text-slate-400">Ulica, poštanski broj i grad — koriste se za adresu u ugovoru.</p>

            <div class="mt-5 grid gap-6">
                <div>
                    <label for="address_line1" class="block text-sm font-medium text-slate-200">Adresa</label>
                    <input id="address_line1" name="address_line1" type="text" maxlength="200" value="{{ old('address_line1', $profile?->address_line1) }}" autocomplete="address-line1"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                    @error('address_line1')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="address_line2" class="block text-sm font-medium text-slate-200">Adresa (nastavak)</label>
                    <input id="address_line2" name="address_line2" type="text" maxlength="200" value="{{ old('address_line2', $profile?->address_line2) }}" autocomplete="address-line2"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                    @error('address_line2')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label for="postal_code" class="block text-sm font-medium text-slate-200">Poštanski broj</label>
                        <input id="postal_code" name="postal_code" type="text" maxlength="20" value="{{ old('postal_code', $profile?->postal_code) }}" autocomplete="postal-code"
                            class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                        @error('postal_code')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="city" class="block text-sm font-medium text-slate-200">Grad</label>
                        <input id="city" name="city" type="text" maxlength="100" value="{{ old('city', $profile?->city) }}" autocomplete="address-level2"
                            class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                        @error('city')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center gap-3">
                <h2 class="text-base font-semibold text-white">Kontakt</h2>
                <x-badge tone="slate">Ne prenosi se u ugovor</x-badge>
            </div>
            <p class="mt-1 text-sm text-slate-400">Država i telefon <strong>nikad</strong> ne ulaze u automatsko popunjavanje ugovora.</p>

            <div class="mt-5 grid gap-6 sm:grid-cols-2">
                <div>
                    <label for="country_code" class="block text-sm font-medium text-slate-200">Država (kod)</label>
                    <input id="country_code" name="country_code" type="text" maxlength="2" value="{{ old('country_code', $profile?->country_code) }}" aria-describedby="country_code_help" autocomplete="country"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm uppercase text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                    <p id="country_code_help" class="mt-2 text-xs text-slate-500">Dva slova, npr. HR. Neobavezno.</p>
                    @error('country_code')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-slate-200">Telefon</label>
                    <input id="phone" name="phone" type="text" maxlength="30" value="{{ old('phone', $profile?->phone) }}" autocomplete="tel"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                    @error('phone')<p class="mt-2 text-xs text-red-300" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-card>

        <div class="flex flex-col gap-3 sm:flex-row">
            <x-action type="submit" variant="primary">Spremi profil</x-action>
            <x-action href="{{ route('dashboard') }}" variant="secondary">Natrag na dashboard</x-action>
        </div>
    </form>
</x-app-layout>
