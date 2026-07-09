<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Izrada ugovora | Digital Sign Master Degree</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="h-full bg-slate-950 text-slate-100 antialiased">
    <div class="flex min-h-screen flex-col">
        <header class="sticky top-0 z-50 border-b border-white/10 bg-slate-950/90 backdrop-blur-xl">
            <div class="mx-auto flex max-w-[1800px] flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">DSMD</p>
                    <h1 class="mt-1 text-2xl font-semibold text-white">Izrada kupoprodajnog ugovora</h1>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @if ($contractId)
                            <span class="rounded-full border border-emerald-300/30 bg-emerald-300/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-emerald-200">
                                Nacrt — uređivanje omogućeno
                            </span>
                            <p class="text-sm font-medium text-emerald-200">
                                Nastavljate uređivanje spremljenog drafta.
                            </p>
                        @else
                            <span class="rounded-full border border-cyan-300/30 bg-cyan-300/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-cyan-200">
                                Novi ugovor — još nije spremljen
                            </span>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('dashboard') }}" class="rounded-full border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                        Dashboard
                    </a>

                    <button
                        type="button"
                        id="fakeSaveButton"
                        class="rounded-full border border-emerald-300/20 bg-emerald-300/10 px-5 py-2.5 text-sm font-bold text-emerald-100 transition hover:bg-emerald-300/15"
                    >
                        Spremi promjene
                    </button>
                </div>
            </div>
        </header>

        <main class="grid flex-1 gap-0 xl:grid-cols-[1fr_520px]">
            {{-- LIJEVA STRANA: PREVIEW --}}
            <section class="hidden bg-slate-900 xl:block xl:h-[calc(100vh-97px)] xl:overflow-hidden">
                <iframe
                    id="contractPreviewFrame"
                    src="{{ route('contracts.vehicle-sales-preview') }}"
                    class="h-full w-full border-0"
                    title="Pregled ugovora o kupoprodaji motornog vozila"
                ></iframe>
            </section>

            {{-- DESNA STRANA: FORMA (redoslijed prati originalni ugovor) --}}
            <aside class="border-l border-white/10 bg-slate-950 px-5 py-8 xl:h-[calc(100vh-97px)] xl:overflow-auto">
                <form id="contractBuilderForm" class="space-y-6">
                    @csrf

                    {{-- 1. PRODAVATELJ I KUPAC --}}
                    <section class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-5">
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-emerald-200">1 · Prodavatelj i kupac</p>

                        <div class="mt-5 grid gap-6 sm:grid-cols-2">
                            <fieldset class="grid gap-4">
                                <legend class="text-xs font-bold uppercase tracking-wider text-emerald-100">Prodavatelj</legend>

                                <div>
                                    <label for="seller_name" class="block text-sm font-medium text-slate-200">Ime i prezime / naziv</label>
                                    <input id="seller_name" name="seller_name" type="text" data-field="seller_name" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/20" placeholder="Ivan Horvat">
                                </div>

                                <div>
                                    <label for="seller_address" class="block text-sm font-medium text-slate-200">Adresa</label>
                                    <input id="seller_address" name="seller_address" type="text" data-field="seller_address" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/20" placeholder="Ulica 1, Zagreb">
                                </div>

                                <div>
                                    <label for="seller_oib" class="block text-sm font-medium text-slate-200">OIB</label>
                                    <input id="seller_oib" name="seller_oib" type="text" maxlength="11" data-field="seller_oib" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/20" placeholder="12345678901">
                                </div>
                            </fieldset>

                            <fieldset class="grid gap-4">
                                <legend class="text-xs font-bold uppercase tracking-wider text-cyan-100">Kupac</legend>

                                <div>
                                    <label for="buyer_name" class="block text-sm font-medium text-slate-200">Ime i prezime / naziv</label>
                                    <input id="buyer_name" name="buyer_name" type="text" data-field="buyer_name" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20" placeholder="Mateo Ljevar">
                                </div>

                                <div>
                                    <label for="buyer_address" class="block text-sm font-medium text-slate-200">Adresa</label>
                                    <input id="buyer_address" name="buyer_address" type="text" data-field="buyer_address" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20" placeholder="Ulica 2, Zagreb">
                                </div>

                                <div>
                                    <label for="buyer_oib" class="block text-sm font-medium text-slate-200">OIB</label>
                                    <input id="buyer_oib" name="buyer_oib" type="text" maxlength="11" data-field="buyer_oib" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20" placeholder="10987654321">
                                </div>
                            </fieldset>
                        </div>
                    </section>

                    {{-- 2. DATUM I MJESTO SKLAPANJA --}}
                    <section class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-5">
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-cyan-200">2 · Datum i mjesto sklapanja</p>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="place" class="block text-sm font-medium text-slate-200">Mjesto sklapanja</label>
                                <input id="place" name="place" type="text" data-field="place" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20" placeholder="Zagreb">
                            </div>

                            <div>
                                <label for="contract_date" class="block text-sm font-medium text-slate-200">Datum ugovora</label>
                                <input id="contract_date" name="contract_date" type="date" data-field="contract_date" data-format="date" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-300/20">
                            </div>
                        </div>
                    </section>

                    {{-- 3. VOZILO (redoslijed polja prati obrazac) --}}
                    <section class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-5">
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-indigo-200">3 · Vozilo</p>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="registration_number" class="block text-sm font-medium text-slate-200">Registarska oznaka</label>
                                <input id="registration_number" name="registration_number" type="text" data-field="registration_number" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="ZG-1234-AB">
                            </div>

                            <div>
                                <label for="vehicle_type" class="block text-sm font-medium text-slate-200">Vrsta vozila</label>
                                <input id="vehicle_type" name="vehicle_type" type="text" data-field="vehicle_type" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="Osobni automobil">
                            </div>

                            <div>
                                <label for="vehicle_brand" class="block text-sm font-medium text-slate-200">Marka vozila</label>
                                <input id="vehicle_brand" name="vehicle_brand" type="text" data-field="vehicle_brand" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="Volkswagen">
                            </div>

                            <div>
                                <label for="vehicle_tip" class="block text-sm font-medium text-slate-200">Tip vozila</label>
                                <input id="vehicle_tip" name="vehicle_tip" type="text" data-field="vehicle_tip" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="1.2 TDI">
                            </div>

                            <div>
                                <label for="vehicle_model" class="block text-sm font-medium text-slate-200">Model vozila</label>
                                <input id="vehicle_model" name="vehicle_model" type="text" data-field="vehicle_model" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="Polo 6R">
                            </div>

                            <div>
                                <label for="vehicle_color" class="block text-sm font-medium text-slate-200">Boja vozila</label>
                                <input id="vehicle_color" name="vehicle_color" type="text" data-field="vehicle_color" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="Crna">
                            </div>

                            <div class="sm:col-span-2">
                                <label for="vin" class="block text-sm font-medium text-slate-200">Broj šasije / VIN</label>
                                <input id="vin" name="vin" type="text" data-field="vin" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="WVWZZZ6RZCY000000">
                            </div>

                            <div>
                                <label for="body_shape" class="block text-sm font-medium text-slate-200">Oblik karoserije</label>
                                <input id="body_shape" name="body_shape" type="text" data-field="body_shape" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="Hatchback">
                            </div>

                            <div>
                                <label for="manufacturer_country" class="block text-sm font-medium text-slate-200">Država proizvodnje i proizvođač</label>
                                <input id="manufacturer_country" name="manufacturer_country" type="text" data-field="manufacturer_country" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="Njemačka / Volkswagen">
                            </div>

                            <div>
                                <label for="production_year" class="block text-sm font-medium text-slate-200">Godina proizvodnje</label>
                                <input id="production_year" name="production_year" type="number" min="1900" max="2100" data-field="production_year" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="2012">
                            </div>

                            <div>
                                <label for="vehicle_purpose" class="block text-sm font-medium text-slate-200">Osnovna namjena</label>
                                <input id="vehicle_purpose" name="vehicle_purpose" type="text" data-field="vehicle_purpose" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="Prijevoz osoba">
                            </div>

                            <div>
                                <label for="first_registration_date" class="block text-sm font-medium text-slate-200">Datum prve registracije</label>
                                <input id="first_registration_date" name="first_registration_date" type="date" data-field="first_registration_date" data-format="date" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20">
                            </div>

                            <div>
                                <label for="engine_type" class="block text-sm font-medium text-slate-200">Vrsta motora</label>
                                <input id="engine_type" name="engine_type" type="text" data-field="engine_type" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="Dizel">
                            </div>

                            <div>
                                <label for="engine_power_kw" class="block text-sm font-medium text-slate-200">Snaga motora u kW</label>
                                <input id="engine_power_kw" name="engine_power_kw" type="number" min="0" data-field="engine_power_kw" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="55">
                            </div>

                            <div>
                                <label for="engine_displacement_cc" class="block text-sm font-medium text-slate-200">Radni obujam motora u cm³</label>
                                <input id="engine_displacement_cc" name="engine_displacement_cc" type="number" min="0" data-field="engine_displacement_cc" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-indigo-300/50 focus:ring-2 focus:ring-indigo-300/20" placeholder="1199">
                            </div>
                        </div>
                    </section>

                    {{-- 4. CIJENA I PLAĆANJE --}}
                    <section class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-5">
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-amber-200">4 · Cijena i plaćanje</p>

                        <div class="mt-5 grid gap-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="price_amount" class="block text-sm font-medium text-slate-200">Prodajna cijena EUR</label>
                                    <input id="price_amount" name="price_amount" type="number" min="0" step="0.01" data-field="price_amount" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-amber-300/50 focus:ring-2 focus:ring-amber-300/20" placeholder="4500">
                                </div>

                                <div>
                                    <label for="price_words" class="block text-sm font-medium text-slate-200">Iznos riječima</label>
                                    <input id="price_words" name="price_words" type="text" data-field="price_words" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-amber-300/50 focus:ring-2 focus:ring-amber-300/20" placeholder="četiri tisuće petsto">
                                </div>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="paid_date" class="block text-sm font-medium text-slate-200">Datum isplate</label>
                                    <input id="paid_date" name="paid_date" type="date" data-field="paid_date" data-format="date" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-amber-300/50 focus:ring-2 focus:ring-amber-300/20">
                                </div>

                                <div>
                                    <label for="paid_amount" class="block text-sm font-medium text-slate-200">Isplaćeni iznos EUR</label>
                                    <input id="paid_amount" name="paid_amount" type="number" min="0" step="0.01" data-field="paid_amount" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-amber-300/50 focus:ring-2 focus:ring-amber-300/20" placeholder="4500">
                                </div>
                            </div>

                            <div>
                                <label for="paid_words" class="block text-sm font-medium text-slate-200">Isplaćeni iznos riječima</label>
                                <input id="paid_words" name="paid_words" type="text" data-field="paid_words" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-amber-300/50 focus:ring-2 focus:ring-amber-300/20" placeholder="četiri tisuće petsto">
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="remaining_amount" class="block text-sm font-medium text-slate-200">Ostatak EUR</label>
                                    <input id="remaining_amount" name="remaining_amount" type="number" min="0" step="0.01" data-field="remaining_amount" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-amber-300/50 focus:ring-2 focus:ring-amber-300/20" placeholder="0">
                                </div>

                                <div>
                                    <label for="remaining_due_date" class="block text-sm font-medium text-slate-200">Rok plaćanja ostatka</label>
                                    <input id="remaining_due_date" name="remaining_due_date" type="date" data-field="remaining_due_date" data-format="date" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-amber-300/50 focus:ring-2 focus:ring-amber-300/20">
                                </div>
                            </div>

                            <div>
                                <label for="remaining_words" class="block text-sm font-medium text-slate-200">Ostatak iznosa riječima</label>
                                <input id="remaining_words" name="remaining_words" type="text" data-field="remaining_words" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-amber-300/50 focus:ring-2 focus:ring-amber-300/20" placeholder="nula">
                            </div>
                        </div>
                    </section>

                    {{-- 5. ZAVRŠNE ODREDBE / PREDANE STVARI / NAPOMENA --}}
                    <section class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-5">
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-rose-200">5 · Završne odredbe, predane stvari i napomena</p>

                        <div class="mt-5 grid gap-4">
                            <div>
                                <label for="included_items" class="block text-sm font-medium text-slate-200">Predane stvari uz vozilo</label>
                                <input id="included_items" name="included_items" type="text" data-field="included_items" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-rose-300/50 focus:ring-2 focus:ring-rose-300/20" placeholder="prometna dozvola, ključevi, servisna knjižica">
                            </div>

                            <div>
                                <label for="costs_paid_by" class="block text-sm font-medium text-slate-200">Upravnu pristojbu i ostale troškove snosi</label>
                                <select id="costs_paid_by" name="costs_paid_by" data-field="costs_paid_by" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-rose-300/50 focus:ring-2 focus:ring-rose-300/20">
                                    <option value="">Odaberi</option>
                                    <option value="kupac">kupac</option>
                                    <option value="prodavatelj">prodavatelj</option>
                                    <option value="kupac i prodavatelj">kupac i prodavatelj</option>
                                </select>
                            </div>

                            <div>
                                <label for="court_place" class="block text-sm font-medium text-slate-200">Nadležni sud u</label>
                                <input id="court_place" name="court_place" type="text" data-field="court_place" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-rose-300/50 focus:ring-2 focus:ring-rose-300/20" placeholder="Zagrebu">
                            </div>

                            <div>
                                <label for="note" class="block text-sm font-medium text-slate-200">Napomena</label>
                                <textarea id="note" name="note" rows="4" data-field="note" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-rose-300/50 focus:ring-2 focus:ring-rose-300/20" placeholder="Dodatne napomene ugovornih strana..."></textarea>
                            </div>
                        </div>
                    </section>

                    {{-- 6. FINALIZACIJA I DOKUMENT --}}
                    <section class="rounded-[1.75rem] border border-amber-300/20 bg-amber-300/[0.04] p-5">
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-amber-200">6 · Finalizacija i dokument</p>

                        <p class="mt-4 text-sm leading-6 text-slate-300">
                            Dok je ugovor u statusu nacrta, možete ga slobodno uređivati i spremati.
                            Finalizacijom se snapshot ugovora zaključava, uređivanje se trajno onemogućuje,
                            a finalni PDF generira se iz zaključane verzije. Finalizacija nije digitalni potpis.
                        </p>

                        <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                            <button
                                type="button"
                                id="fakePdfButton"
                                class="rounded-full bg-cyan-300 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-200"
                            >
                                Preuzmi probni PDF
                            </button>

                            @if ($contractId && $contractStatus === \App\Models\Contract::STATUS_DRAFT)
                                <button
                                    type="button"
                                    id="openFinalizeModalButton"
                                    class="rounded-full border border-amber-300/30 bg-amber-300/10 px-5 py-2.5 text-sm font-bold text-amber-100 transition hover:bg-amber-300/15"
                                >
                                    Finaliziraj i zaključaj
                                </button>
                            @else
                                <p class="self-center text-sm text-slate-400">
                                    Finalizacija je dostupna nakon prvog spremanja ugovora.
                                </p>
                            @endif
                        </div>
                    </section>
                </form>
            </aside>
        </main>
    </div>

    <div id="toast" class="fixed bottom-5 right-5 hidden rounded-2xl border border-cyan-300/20 bg-slate-900 px-5 py-4 text-sm text-cyan-100 shadow-2xl shadow-slate-950/70">
        Promjene su vidljive u previewu. Spremanje u bazu ide u sljedećem koraku.
    </div>

    @if ($contractId && $contractStatus === \App\Models\Contract::STATUS_DRAFT)
        <div id="finalizeModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/80 px-5 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="finalizeModalTitle">
            <div class="w-full max-w-lg rounded-[2rem] border border-amber-300/20 bg-slate-900 p-6 shadow-2xl shadow-black/60">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-200">Zaključavanje ugovora</p>
                <h2 id="finalizeModalTitle" class="mt-2 text-2xl font-semibold text-white">Finalizirati ugovor?</h2>
                <p class="mt-4 text-sm leading-6 text-slate-300">
                    Nakon finalizacije ugovor više neće biti moguće uređivati. Snapshot će biti zaključan, a finalni PDF će se generirati iz zaključane verzije ugovora. Ova akcija nije digitalni potpis.
                </p>

                <div id="finalizeValidationErrors" class="mt-5 hidden rounded-2xl border border-red-300/20 bg-red-300/[0.07] px-4 py-3 text-sm text-red-100">
                    <p class="font-semibold">Ugovor nije spreman za finalizaciju.</p>
                    <ul id="finalizeValidationErrorList" class="mt-2 list-disc space-y-1 pl-5"></ul>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" id="cancelFinalizeButton" class="rounded-full border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold text-slate-200 transition hover:bg-white/10">
                        Odustani
                    </button>
                    <button type="button" id="confirmFinalizeButton" class="rounded-full border border-amber-300/30 bg-amber-300 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200">
                        Finaliziraj i zaključaj
                    </button>
                </div>
            </div>
        </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('contractBuilderForm');
            const iframe = document.getElementById('contractPreviewFrame');
            const fakeSaveButton = document.getElementById('fakeSaveButton');
            const fakePdfButton = document.getElementById('fakePdfButton');
            const openFinalizeModalButton = document.getElementById('openFinalizeModalButton');
            const finalizeModal = document.getElementById('finalizeModal');
            const cancelFinalizeButton = document.getElementById('cancelFinalizeButton');
            const confirmFinalizeButton = document.getElementById('confirmFinalizeButton');
            const finalizeValidationErrors = document.getElementById('finalizeValidationErrors');
            const finalizeValidationErrorList = document.getElementById('finalizeValidationErrorList');
            const toast = document.getElementById('toast');
            const saveUrl = @json(route('contracts.snapshot.store'));
            const finalizeUrl = @json($contractId ? route('contracts.finalize.store', $contractId) : null);
            const initialSnapshot = @json($snapshot);
            let currentContractId = @json($contractId);
            let hasUnsavedChanges = false;

            const formatDate = (value) => {
                if (! value) {
                    return '';
                }

                const parts = value.split('-');

                if (parts.length !== 3) {
                    return value;
                }

                return `${parts[2]}.${parts[1]}.${parts[0]}.`;
            };

            const getFieldValue = (field) => {
                let value = field.value.trim();

                if (field.dataset.format === 'date') {
                    value = formatDate(value);
                }

                return value;
            };

            const collectValues = () => {
                const values = {};
                const fields = form.querySelectorAll('[data-field]');

                fields.forEach((field) => {
                    values[field.dataset.field] = getFieldValue(field);
                });

                return values;
            };

            const hydrateForm = (values) => {
                Object.entries(values || {}).forEach(([key, value]) => {
                    const field = form.querySelector(`[data-field="${key}"]`);

                    if (! field || value === null || value === undefined) {
                        return;
                    }

                    field.value = String(value);
                });
            };

            const updatePreview = () => {
                const values = collectValues();

                iframe.contentWindow.postMessage({
                    type: 'contract-preview:update',
                    values: values,
                }, window.location.origin);
            };

            const showToast = (message) => {
                if (! toast) {
                    return;
                }

                toast.textContent = message;
                toast.classList.remove('hidden');

                setTimeout(() => {
                    toast.classList.add('hidden');
                }, 2800);
            };

            const saveSnapshot = async () => {
                const payload = Object.fromEntries(new FormData(form).entries());

                delete payload._token;

                if (currentContractId) {
                    payload.contract_id = currentContractId;
                }

                fakeSaveButton.disabled = true;
                fakeSaveButton.textContent = 'Spremanje...';

                try {
                    const response = await fetch(saveUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                        },
                        body: JSON.stringify(payload),
                    });

                    const data = await response.json();

                    if (! response.ok) {
                        const validationMessage = data.errors
                            ? Object.values(data.errors).flat()[0]
                            : null;

                        throw new Error(validationMessage || data.message || 'Snapshot nije spremljen.');
                    }

                    currentContractId = data.contract_id;
                    hasUnsavedChanges = false;
                    showToast(data.message);
                } catch (error) {
                    showToast(error.message || 'Snapshot nije spremljen.');
                } finally {
                    fakeSaveButton.disabled = false;
                    fakeSaveButton.textContent = 'Spremi promjene';
                }
            };

            const markAsChangedAndUpdatePreview = () => {
                hasUnsavedChanges = true;
                updatePreview();
            };

            const closeFinalizeModal = () => {
                finalizeModal?.classList.add('hidden');
                finalizeModal?.classList.remove('flex');
            };

            const openFinalizeModal = () => {
                if (hasUnsavedChanges) {
                    showToast('Imate nespremljene promjene. Prvo spremite promjene prije finalizacije.');

                    return;
                }

                finalizeValidationErrors?.classList.add('hidden');
                finalizeValidationErrorList?.replaceChildren();
                finalizeModal?.classList.remove('hidden');
                finalizeModal?.classList.add('flex');
            };

            const showFinalizeValidationErrors = (data) => {
                const fields = [
                    ...(data.missing_fields || []).map((field) => `${field.label}: obavezno polje nije uneseno.`),
                    ...(data.invalid_fields || []).map((field) => `${field.label}: ${field.reason}`),
                ];

                finalizeValidationErrorList?.replaceChildren(
                    ...fields.map((message) => {
                        const item = document.createElement('li');
                        item.textContent = message;

                        return item;
                    })
                );
                finalizeValidationErrors?.classList.remove('hidden');
            };

            const finalizeContract = async () => {
                if (! finalizeUrl || ! currentContractId) {
                    return;
                }

                confirmFinalizeButton.disabled = true;
                confirmFinalizeButton.textContent = 'Finaliziranje...';

                try {
                    const response = await fetch(finalizeUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                        },
                    });
                    const data = await response.json();

                    if (! response.ok) {
                        if (response.status === 422) {
                            showFinalizeValidationErrors(data);
                        }

                        throw new Error(data.message || 'Ugovor nije spreman za finalizaciju.');
                    }

                    window.location.assign(data.redirect_url);
                } catch (error) {
                    showToast(error.message || 'Ugovor nije spreman za finalizaciju.');
                } finally {
                    confirmFinalizeButton.disabled = false;
                    confirmFinalizeButton.textContent = 'Finaliziraj i zaključaj';
                }
            };

            form.addEventListener('input', markAsChangedAndUpdatePreview);
            form.addEventListener('change', markAsChangedAndUpdatePreview);

            hydrateForm(initialSnapshot);
            updatePreview();
            iframe.addEventListener('load', updatePreview);

            fakeSaveButton?.addEventListener('click', async () => {
                updatePreview();
                await saveSnapshot();
            });

            fakePdfButton?.addEventListener('click', () => {
                showToast('PDF download dodajemo nakon što zaključamo HTML preview.');
            });
            openFinalizeModalButton?.addEventListener('click', openFinalizeModal);
            cancelFinalizeButton?.addEventListener('click', closeFinalizeModal);
            confirmFinalizeButton?.addEventListener('click', finalizeContract);
            finalizeModal?.addEventListener('click', (event) => {
                if (event.target === finalizeModal) {
                    closeFinalizeModal();
                }
            });
        });
    </script>
</body>
</html>
