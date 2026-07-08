<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <title>Kupoprodajni ugovor - finalizirani PDF</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 22mm 18mm 28mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #172033;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5px;
            line-height: 1.42;
        }

        h1, h2, p {
            margin-top: 0;
        }

        h1 {
            margin-bottom: 4px;
            color: #101827;
            font-size: 21px;
            letter-spacing: 0.7px;
            text-align: center;
        }

        .document-subtitle {
            margin-bottom: 14px;
            color: #64748b;
            font-size: 9px;
            letter-spacing: 1.2px;
            text-align: center;
            text-transform: uppercase;
        }

        .final-label {
            margin: 0 auto 16px;
            border: 1.5px solid #0f766e;
            color: #0f766e;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1.4px;
            padding: 7px 12px;
            text-align: center;
            width: 230px;
        }

        .intro {
            margin-bottom: 13px;
            text-align: justify;
        }

        .section {
            margin-bottom: 12px;
            page-break-inside: avoid;
        }

        .section-title {
            margin: 0 0 6px;
            border-bottom: 1px solid #94a3b8;
            color: #0f172a;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.4px;
            padding-bottom: 4px;
            text-transform: uppercase;
        }

        table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        .data-table th,
        .data-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 7px;
            text-align: left;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .data-table th {
            background: #f1f5f9;
            color: #475569;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            width: 31%;
        }

        .two-column-table td {
            padding: 0;
            vertical-align: top;
            width: 49%;
        }

        .two-column-table td.spacer {
            width: 2%;
        }

        .party-box {
            border: 1px solid #cbd5e1;
            min-height: 104px;
            padding: 8px;
        }

        .party-heading {
            margin-bottom: 7px;
            color: #0f766e;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        .party-row {
            margin-bottom: 5px;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .party-label {
            color: #64748b;
            display: block;
            font-size: 7.5px;
            text-transform: uppercase;
        }

        .amount {
            color: #0f172a;
            font-size: 13px;
            font-weight: bold;
        }

        .statement {
            margin: 4px 0 0;
            text-align: justify;
        }

        .signature-section {
            margin-top: 22px;
            page-break-inside: avoid;
        }

        .signature-table td {
            padding: 0 20px;
            text-align: center;
            vertical-align: bottom;
            width: 50%;
        }

        .signature-space {
            height: 34px;
        }

        .signature-line {
            border-top: 1px solid #475569;
            padding-top: 5px;
        }

        .signature-role {
            color: #475569;
            font-size: 8px;
            text-transform: uppercase;
        }

        .notice {
            margin-top: 18px;
            border: 1px solid #99f6e4;
            background: #f0fdfa;
            color: #134e4a;
            font-size: 8.5px;
            padding: 8px 10px;
            page-break-inside: avoid;
            text-align: center;
        }

        .technical-footer {
            position: fixed;
            right: 0;
            bottom: -18mm;
            left: 0;
            border-top: 1px solid #cbd5e1;
            color: #64748b;
            font-size: 6.8px;
            line-height: 1.35;
            padding-top: 5px;
        }

        .footer-table td {
            padding: 0;
            vertical-align: top;
        }

        .footer-table .footer-main {
            overflow-wrap: break-word;
            padding-right: 28mm;
            width: 100%;
            word-wrap: break-word;
        }

        .hash {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 6.4px;
        }

        .verification-section {
            margin-top: 14px;
            border: 1px solid #cbd5e1;
            padding: 8px;
            page-break-inside: avoid;
        }

        .verification-table td {
            padding: 0;
            vertical-align: middle;
        }

        .verification-qr {
            width: 76px;
        }

        .verification-copy {
            padding-left: 10px !important;
        }

        .verification-url {
            color: #0f766e;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 7px;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    @php
        $display = static fn ($value) => filled($value) ? $value : 'Nije uneseno';
        $vehicle = trim(implode(' ', array_filter([
            data_get($snapshot, 'vehicle_brand'),
            data_get($snapshot, 'vehicle_model'),
            data_get($snapshot, 'vehicle_tip'),
        ])));
        $contractDate = data_get($snapshot, 'contract_date');
        $formattedContractDate = $contractDate
            ? \Illuminate\Support\Carbon::parse($contractDate)->format('d.m.Y.')
            : 'Nije uneseno';
        $qrCodeDataUri ??= null;
        $verificationUrl ??= null;
    @endphp

    <footer class="technical-footer">
        <table class="footer-table">
            <tr>
                <td class="footer-main">
                    Ugovor ID: {{ $contract->id }}
                    &nbsp;|&nbsp; Finalizirano: {{ $contract->finalized_at?->format('d.m.Y. H:i:s') ?? 'N/A' }}<br>
                    Snapshot SHA-256:
                    <span class="hash">{{ $contract->finalized_snapshot_sha256 ?? 'N/A' }}</span><br>
                    SHA-256 finalnog PDF-a dostupan je na javnoj stranici za provjeru dokumenta.
                </td>
            </tr>
        </table>
    </footer>

    <h1>KUPOPRODAJNI UGOVOR</h1>
    <p class="document-subtitle">o kupoprodaji motornog vozila</p>
    <div class="final-label">FINALIZIRANI UGOVOR</div>

    <p class="intro">
        Ugovorne strane u nastavku sklapaju ovaj ugovor o kupoprodaji motornog vozila
        pod uvjetima navedenima u ovom dokumentu.
    </p>

    <section class="section">
        <h2 class="section-title">1. Ugovorne strane</h2>
        <table class="two-column-table">
            <tr>
                <td>
                    <div class="party-box">
                        <div class="party-heading">Prodavatelj</div>
                        <div class="party-row">
                            <span class="party-label">Ime i prezime / naziv</span>
                            {{ $display(data_get($snapshot, 'seller_name')) }}
                        </div>
                        <div class="party-row">
                            <span class="party-label">Adresa</span>
                            {{ $display(data_get($snapshot, 'seller_address')) }}
                        </div>
                        <div class="party-row">
                            <span class="party-label">OIB</span>
                            {{ $display(data_get($snapshot, 'seller_oib')) }}
                        </div>
                    </div>
                </td>
                <td class="spacer"></td>
                <td>
                    <div class="party-box">
                        <div class="party-heading">Kupac</div>
                        <div class="party-row">
                            <span class="party-label">Ime i prezime / naziv</span>
                            {{ $display(data_get($snapshot, 'buyer_name')) }}
                        </div>
                        <div class="party-row">
                            <span class="party-label">Adresa</span>
                            {{ $display(data_get($snapshot, 'buyer_address')) }}
                        </div>
                        <div class="party-row">
                            <span class="party-label">OIB</span>
                            {{ $display(data_get($snapshot, 'buyer_oib')) }}
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">2. Predmet ugovora — vozilo</h2>
        <table class="data-table">
            <tr>
                <th>Vozilo</th>
                <td>{{ $display($vehicle) }}</td>
                <th>Registracija</th>
                <td>{{ $display(data_get($snapshot, 'registration_number')) }}</td>
            </tr>
            <tr>
                <th>VIN / broj šasije</th>
                <td colspan="3">{{ $display(data_get($snapshot, 'vin')) }}</td>
            </tr>
            <tr>
                <th>Vrsta vozila</th>
                <td>{{ $display(data_get($snapshot, 'vehicle_type')) }}</td>
                <th>Boja</th>
                <td>{{ $display(data_get($snapshot, 'vehicle_color')) }}</td>
            </tr>
            <tr>
                <th>Godina proizvodnje</th>
                <td>{{ $display(data_get($snapshot, 'production_year')) }}</td>
                <th>Prva registracija</th>
                <td>{{ $display(data_get($snapshot, 'first_registration_date')) }}</td>
            </tr>
            <tr>
                <th>Motor</th>
                <td>{{ $display(data_get($snapshot, 'engine_type')) }}</td>
                <th>Snaga / obujam</th>
                <td>
                    {{ $display(data_get($snapshot, 'engine_power_kw')) }} kW /
                    {{ $display(data_get($snapshot, 'engine_displacement_cc')) }} cm³
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">3. Kupoprodajna cijena i plaćanje</h2>
        <table class="data-table">
            <tr>
                <th>Kupoprodajna cijena</th>
                <td class="amount">{{ $display(data_get($snapshot, 'price_amount')) }} EUR</td>
            </tr>
            <tr>
                <th>Iznos riječima</th>
                <td>{{ $display(data_get($snapshot, 'price_words')) }}</td>
            </tr>
            <tr>
                <th>Isplaćeni iznos</th>
                <td>{{ $display(data_get($snapshot, 'paid_amount')) }} EUR</td>
            </tr>
            <tr>
                <th>Preostali iznos</th>
                <td>
                    {{ $display(data_get($snapshot, 'remaining_amount')) }} EUR
                    @if (filled(data_get($snapshot, 'remaining_due_date')))
                        — rok plaćanja: {{ data_get($snapshot, 'remaining_due_date') }}
                    @endif
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">4. Datum i mjesto sklapanja</h2>
        <table class="data-table">
            <tr>
                <th>Mjesto ugovora</th>
                <td>{{ $display(data_get($snapshot, 'place')) }}</td>
                <th>Datum ugovora</th>
                <td>{{ $formattedContractDate }}</td>
            </tr>
            <tr>
                <th>Nadležni sud</th>
                <td colspan="3">{{ $display(data_get($snapshot, 'court_place')) }}</td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">5. Završne odredbe</h2>
        <p class="statement">
            Prodavatelj izjavljuje da kupcu predaje opisano vozilo, pripadajuće isprave i
            stvari navedene u ugovoru, a kupac potvrđuje da je upoznat sa stanjem vozila
            te ga prihvaća pod uvjetima iz ovog ugovora.
        </p>
        <p class="statement">
            Ugovorne strane potvrđuju da su pročitale i razumjele sadržaj ugovora, da
            ugovor odražava njihovu slobodnu i ozbiljnu volju te da ga prihvaćaju u cijelosti.
        </p>
        @if (filled(data_get($snapshot, 'included_items')))
            <p class="statement">
                <strong>Predane stvari uz vozilo:</strong>
                {{ data_get($snapshot, 'included_items') }}
            </p>
        @endif
        @if (filled(data_get($snapshot, 'note')))
            <p class="statement">
                <strong>Napomena:</strong> {{ data_get($snapshot, 'note') }}
            </p>
        @endif
    </section>

    <section class="signature-section">
        <table class="signature-table">
            <tr>
                <td>
                    <div class="signature-space"></div>
                    <div class="signature-line">
                        {{ $display(data_get($snapshot, 'seller_name')) }}<br>
                        <span class="signature-role">Prodavatelj</span>
                    </div>
                </td>
                <td>
                    <div class="signature-space"></div>
                    <div class="signature-line">
                        {{ $display(data_get($snapshot, 'buyer_name')) }}<br>
                        <span class="signature-role">Kupac</span>
                    </div>
                </td>
            </tr>
        </table>
    </section>

    <div class="notice">
        Ovaj PDF je generiran iz zaključanog snapshota ugovora.
        Dokument nije kriptografski digitalno potpisan.
    </div>

    @if ($qrCodeDataUri && $verificationUrl)
        <section class="verification-section">
            <table class="verification-table">
                <tr>
                    <td class="verification-qr">
                        <img src="{{ $qrCodeDataUri }}" width="76" height="76" alt="QR code za javnu provjeru">
                    </td>
                    <td class="verification-copy">
                        <strong>Skenirajte QR code za javnu provjeru hash vrijednosti dokumenta.</strong>
                        <p class="verification-url">{{ $verificationUrl }}</p>
                        <p>Dokument nije kriptografski digitalno potpisan.</p>
                    </td>
                </tr>
            </table>
        </section>
    @endif

    <script type="text/php">
        if (isset($pdf, $fontMetrics)) {
            $font = $fontMetrics->get_font('DejaVu Sans', 'normal');
            $pdf->page_text(
                496,
                815,
                'Stranica {PAGE_NUM} / {PAGE_COUNT}',
                $font,
                7,
                [0.39, 0.45, 0.55]
            );
        }
    </script>
</body>
</html>
