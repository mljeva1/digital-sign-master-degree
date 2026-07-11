<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Moj profil | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto grid min-h-screen max-w-3xl place-items-center px-5 py-8">
        <div class="w-full rounded-[2rem] border border-white/10 bg-slate-900/80 p-6 shadow-2xl shadow-slate-950/60 sm:p-8">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">Moj profil</p>
                <h1 class="mt-3 text-3xl font-semibold text-white">Podaci za kupoprodajni ugovor</h1>
                <p class="mt-3 text-sm leading-6 text-slate-400">
                    Sva su polja neobavezna. Spremljeni podaci ostaju vezani samo uz tvoj račun i mogu
                    ubrzati kasniju izradu ugovora. Prazna polja ostaju prazna.
                </p>
            </div>

            @if (session('success'))
                <div class="mt-6 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100" role="status" aria-live="polite">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-6 rounded-2xl border border-red-300/20 bg-red-300/10 px-4 py-3 text-sm text-red-100" role="alert">
                    <p class="font-semibold">Provjeri unesene podatke:</p>
                    <ul class="mt-2 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('profile.update') }}" class="mt-8 space-y-6">
                @csrf
                @method('PATCH')

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-slate-200">Ime</label>
                        <input
                            id="first_name"
                            name="first_name"
                            type="text"
                            maxlength="100"
                            value="{{ old('first_name', $profile?->first_name) }}"
                            class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                            autocomplete="given-name"
                        >
                        @error('first_name')
                            <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="last_name" class="block text-sm font-medium text-slate-200">Prezime</label>
                        <input
                            id="last_name"
                            name="last_name"
                            type="text"
                            maxlength="100"
                            value="{{ old('last_name', $profile?->last_name) }}"
                            class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                            autocomplete="family-name"
                        >
                        @error('last_name')
                            <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="oib" class="block text-sm font-medium text-slate-200">OIB</label>
                    <input
                        id="oib"
                        name="oib"
                        type="text"
                        inputmode="numeric"
                        maxlength="11"
                        value="{{ old('oib', $profile?->oib) }}"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                        aria-describedby="oib_help"
                    >
                    <p id="oib_help" class="mt-2 text-xs text-slate-500">Točno 11 znamenki, bez razmaka. Neobavezno.</p>
                    @error('oib')
                        <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="address_line1" class="block text-sm font-medium text-slate-200">Adresa</label>
                    <input
                        id="address_line1"
                        name="address_line1"
                        type="text"
                        maxlength="200"
                        value="{{ old('address_line1', $profile?->address_line1) }}"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                        autocomplete="address-line1"
                    >
                    @error('address_line1')
                        <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="address_line2" class="block text-sm font-medium text-slate-200">Adresa (nastavak)</label>
                    <input
                        id="address_line2"
                        name="address_line2"
                        type="text"
                        maxlength="200"
                        value="{{ old('address_line2', $profile?->address_line2) }}"
                        class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                        autocomplete="address-line2"
                    >
                    @error('address_line2')
                        <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label for="postal_code" class="block text-sm font-medium text-slate-200">Poštanski broj</label>
                        <input
                            id="postal_code"
                            name="postal_code"
                            type="text"
                            maxlength="20"
                            value="{{ old('postal_code', $profile?->postal_code) }}"
                            class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                            autocomplete="postal-code"
                        >
                        @error('postal_code')
                            <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="city" class="block text-sm font-medium text-slate-200">Grad</label>
                        <input
                            id="city"
                            name="city"
                            type="text"
                            maxlength="100"
                            value="{{ old('city', $profile?->city) }}"
                            class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                            autocomplete="address-level2"
                        >
                        @error('city')
                            <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label for="country_code" class="block text-sm font-medium text-slate-200">Država (kod)</label>
                        <input
                            id="country_code"
                            name="country_code"
                            type="text"
                            maxlength="2"
                            value="{{ old('country_code', $profile?->country_code) }}"
                            class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm uppercase text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                            aria-describedby="country_code_help"
                            autocomplete="country"
                        >
                        <p id="country_code_help" class="mt-2 text-xs text-slate-500">Dva slova, npr. HR. Neobavezno.</p>
                        @error('country_code')
                            <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-slate-200">Telefon</label>
                        <input
                            id="phone"
                            name="phone"
                            type="text"
                            maxlength="30"
                            value="{{ old('phone', $profile?->phone) }}"
                            class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20"
                            autocomplete="tel"
                        >
                        @error('phone')
                            <p class="mt-2 text-xs text-red-300">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <button type="submit" class="inline-flex justify-center rounded-full bg-cyan-300 px-6 py-3 text-sm font-bold text-slate-950 transition hover:bg-cyan-200">
                        Spremi profil
                    </button>

                    <a href="{{ route('dashboard') }}" class="inline-flex justify-center rounded-full border border-white/10 bg-white/5 px-6 py-3 text-sm font-bold text-white transition hover:bg-white/10">
                        Natrag na dashboard
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
