<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <title>Ugovor o kupoprodaji motornog vozila - finalizirani PDF</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 16mm 16mm 24mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5px;
            line-height: 1.45;
        }

        p {
            margin: 0 0 6px;
        }

        .final-label {
            margin: 0 0 10px;
            color: #555555;
            font-size: 7.5px;
            font-weight: bold;
            letter-spacing: 1.6px;
            text-align: right;
            text-transform: uppercase;
        }

        .parties-table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        .parties-table td {
            padding: 0;
            vertical-align: top;
            width: 48%;
        }

        .parties-table td.spacer {
            width: 4%;
        }

        .party-heading {
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 0.8px;
            margin-bottom: 3px;
            text-align: center;
        }

        .party-box {
            border: 1px solid #333333;
            min-height: 44px;
            padding: 5px 7px;
            text-align: center;
        }

        .party-box .party-name {
            font-size: 10.5px;
            font-weight: bold;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .party-box .party-address {
            font-size: 9px;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .party-caption {
            color: #555555;
            font-size: 7px;
            margin-top: 2px;
            text-align: center;
        }

        .conclusion-line {
            margin: 12px 0 10px;
            text-align: center;
        }

        h1 {
            font-size: 16px;
            letter-spacing: 0.6px;
            margin: 0 0 10px;
            text-align: center;
        }

        .fill {
            border-bottom: 0.75px solid #333333;
            font-weight: bold;
            padding: 0 6px 1px;
            white-space: nowrap;
        }

        .lead-line {
            margin: 0 0 4px;
        }

        .vehicle-table {
            border-collapse: collapse;
            margin-bottom: 10px;
            table-layout: fixed;
            width: 100%;
        }

        .vehicle-table td {
            border: 1px solid #333333;
            overflow-wrap: break-word;
            padding: 3px 5px;
            vertical-align: top;
            word-wrap: break-word;
        }

        .vehicle-table .cell-label {
            color: #555555;
            display: block;
            font-size: 7px;
        }

        .vehicle-table .cell-value {
            display: block;
            font-size: 9.5px;
            font-weight: bold;
            min-height: 12px;
        }

        .payment-block {
            margin-bottom: 8px;
        }

        .clause {
            margin-bottom: 7px;
            text-align: justify;
        }

        .note-block {
            margin-bottom: 8px;
        }

        .note-label {
            font-weight: bold;
        }

        .note-box {
            border: 1px solid #333333;
            min-height: 30px;
            overflow-wrap: break-word;
            padding: 4px 6px;
            word-wrap: break-word;
        }

        .signature-section {
            margin-top: 20px;
            page-break-inside: avoid;
        }

        .signature-table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        .signature-table td {
            padding: 0 14px;
            text-align: center;
            vertical-align: bottom;
            width: 50%;
        }

        .signature-heading {
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 0.8px;
        }

        .signature-space {
            height: 38px;
        }

        .signature-line {
            border-top: 0.75px solid #333333;
            font-size: 8px;
            padding-top: 3px;
        }

        .signature-oib {
            font-size: 9px;
            margin-top: 5px;
            text-align: left;
        }

        .notice {
            border: 0.75px solid #999999;
            color: #444444;
            font-size: 8px;
            margin-top: 14px;
            padding: 6px 9px;
            page-break-inside: avoid;
            text-align: center;
        }

        .verification-section {
            border: 0.75px solid #999999;
            margin-top: 10px;
            padding: 7px;
            page-break-inside: avoid;
        }

        .verification-table {
            border-collapse: collapse;
            width: 100%;
        }

        .verification-table td {
            padding: 0;
            vertical-align: middle;
        }

        .verification-qr {
            width: 76px;
        }

        .verification-copy {
            font-size: 8px;
            padding-left: 10px !important;
        }

        .verification-url {
            color: #333333;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 7px;
            margin: 3px 0;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .technical-footer {
            position: fixed;
            right: 0;
            bottom: -16mm;
            left: 0;
            border-top: 0.75px solid #999999;
            color: #666666;
            font-size: 6.8px;
            line-height: 1.35;
            padding-top: 4px;
        }

        .technical-footer .hash {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 6.4px;
        }
    </style>
</head>
<body>
    @php
        $fill = static fn ($value) => filled($value) ? $value : str_repeat("\u{00A0}", 12);
        $fillDate = static function ($value) {
            if (blank($value)) {
                return str_repeat("\u{00A0}", 12);
            }

            try {
                return \Illuminate\Support\Carbon::parse($value)->format('d.m.Y.');
            } catch (\Throwable) {
                return $value;
            }
        };
        $qrCodeDataUri ??= null;
        $verificationUrl ??= null;
    @endphp

    <footer class="technical-footer">
        Ugovor ID: {{ $contract->id }}
        &nbsp;|&nbsp; Finalizirano: {{ $contract->finalized_at?->format('d.m.Y. H:i:s') ?? 'N/A' }}<br>
        Snapshot SHA-256:
        <span class="hash">{{ $contract->finalized_snapshot_sha256 ?? 'N/A' }}</span><br>
        SHA-256 finalnog PDF-a dostupan je na javnoj stranici za provjeru dokumenta.
    </footer>

    <div class="final-label">FINALIZIRANI UGOVOR</div>

    <table class="parties-table">
        <tr>
            <td>
                <div class="party-heading">PRODAVATELJ</div>
                <div class="party-box">
                    <div class="party-name">{{ $fill(data_get($snapshot, 'seller_name')) }}</div>
                    <div class="party-address">{{ $fill(data_get($snapshot, 'seller_address')) }}</div>
                </div>
                <div class="party-caption">(Ime i prezime fizičke ili naziv pravne osobe i adresa)</div>
            </td>
            <td class="spacer"></td>
            <td>
                <div class="party-heading">KUPAC</div>
                <div class="party-box">
                    <div class="party-name">{{ $fill(data_get($snapshot, 'buyer_name')) }}</div>
                    <div class="party-address">{{ $fill(data_get($snapshot, 'buyer_address')) }}</div>
                </div>
                <div class="party-caption">(Ime i prezime fizičke ili naziv pravne osobe i adresa)</div>
            </td>
        </tr>
    </table>

    <p class="conclusion-line">
        zaključili su u (mjesto) <span class="fill">{{ $fill(data_get($snapshot, 'place')) }}</span>
        (datum) <span class="fill">{{ $fillDate(data_get($snapshot, 'contract_date')) }}</span> godine ovaj:
    </p>

    <h1>UGOVOR O KUPOPRODAJI MOTORNOG VOZILA</h1>

    <p class="lead-line"><strong>Prodavatelj prodaje kupcu motorno vozilo:</strong></p>

    <table class="vehicle-table">
        <tr>
            <td colspan="4">
                <span class="cell-label">Registarska oznaka</span>
                <span class="cell-value">{{ data_get($snapshot, 'registration_number') }}</span>
            </td>
            <td colspan="4">
                <span class="cell-label">Vrsta vozila</span>
                <span class="cell-value">{{ data_get($snapshot, 'vehicle_type') }}</span>
            </td>
            <td colspan="4">
                <span class="cell-label">Marka vozila</span>
                <span class="cell-value">{{ data_get($snapshot, 'vehicle_brand') }}</span>
            </td>
        </tr>
        <tr>
            <td colspan="4">
                <span class="cell-label">Tip vozila</span>
                <span class="cell-value">{{ data_get($snapshot, 'vehicle_tip') }}</span>
            </td>
            <td colspan="4">
                <span class="cell-label">Model vozila</span>
                <span class="cell-value">{{ data_get($snapshot, 'vehicle_model') }}</span>
            </td>
            <td colspan="4">
                <span class="cell-label">Boja vozila</span>
                <span class="cell-value">{{ data_get($snapshot, 'vehicle_color') }}</span>
            </td>
        </tr>
        <tr>
            <td colspan="8">
                <span class="cell-label">Broj šasije (VIN)</span>
                <span class="cell-value">{{ data_get($snapshot, 'vin') }}</span>
            </td>
            <td colspan="4">
                <span class="cell-label">Oblik karoserije</span>
                <span class="cell-value">{{ data_get($snapshot, 'body_shape') }}</span>
            </td>
        </tr>
        <tr>
            <td colspan="4">
                <span class="cell-label">Država proizvodnje i proizvođač</span>
                <span class="cell-value">{{ data_get($snapshot, 'manufacturer_country') }}</span>
            </td>
            <td colspan="4">
                <span class="cell-label">Godina proizvodnje</span>
                <span class="cell-value">{{ data_get($snapshot, 'production_year') }}</span>
            </td>
            <td colspan="4">
                <span class="cell-label">Osnovna namjena</span>
                <span class="cell-value">{{ data_get($snapshot, 'vehicle_purpose') }}</span>
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <span class="cell-label">Datum prve registracije</span>
                <span class="cell-value">{{ filled(data_get($snapshot, 'first_registration_date')) ? $fillDate(data_get($snapshot, 'first_registration_date')) : '' }}</span>
            </td>
            <td colspan="3">
                <span class="cell-label">Vrsta motora</span>
                <span class="cell-value">{{ data_get($snapshot, 'engine_type') }}</span>
            </td>
            <td colspan="3">
                <span class="cell-label">Snaga motora u kW</span>
                <span class="cell-value">{{ data_get($snapshot, 'engine_power_kw') }}</span>
            </td>
            <td colspan="3">
                <span class="cell-label">Radni obujam motora u cm³</span>
                <span class="cell-value">{{ data_get($snapshot, 'engine_displacement_cc') }}</span>
            </td>
        </tr>
    </table>

    <div class="payment-block">
        <p>
            <strong>Prodajna cijena ugovorena je u iznosu</strong>
            <span class="fill">{{ $fill(data_get($snapshot, 'price_amount')) }}</span> EUR;
            iznos riječima <span class="fill">{{ $fill(data_get($snapshot, 'price_words')) }}</span> eura.
        </p>
        <p>
            Kupac je prodavatelju isplatio (datum)
            <span class="fill">{{ $fillDate(data_get($snapshot, 'paid_date')) }}</span> godine;
            (iznos) <span class="fill">{{ $fill(data_get($snapshot, 'paid_amount')) }}</span> EUR;
            iznos riječima <span class="fill">{{ $fill(data_get($snapshot, 'paid_words')) }}</span> eura,
        </p>
        <p>
            a ostatak od prodajne cijene u iznosu od
            <span class="fill">{{ $fill(data_get($snapshot, 'remaining_amount')) }}</span> EUR;
            iznos riječima <span class="fill">{{ $fill(data_get($snapshot, 'remaining_words')) }}</span> eura
            kupac se obvezuje platiti do (datum)
            <span class="fill">{{ $fillDate(data_get($snapshot, 'remaining_due_date')) }}</span> godine.
        </p>
    </div>

    <p class="clause">
        Prodavatelj jamči da je vozilo njegovo vlasništvo i da nije opterećeno ovrhom,
        zabilježbom ili drugim teretom. Kupac je pregledao vozilo i nema prigovora
        u svezi s kvalitetom i prodajnom cijenom.
    </p>

    <p class="clause">
        Uz motorno vozilo, prodavatelj je kupcu predao sljedeće stvari:
        <span class="fill">{{ $fill(data_get($snapshot, 'included_items')) }}</span>
    </p>

    <p class="clause">
        Upravnu pristojbu i ostale troškove snosi
        <span class="fill">{{ $fill(data_get($snapshot, 'costs_paid_by')) }}</span>
    </p>

    <p class="clause">
        Prodavatelj i kupac prihvaćaju prava i obveze iz ovog ugovora,
        a u slučaju spora nadležan je sud u
        <span class="fill">{{ $fill(data_get($snapshot, 'court_place')) }}</span>
    </p>

    <div class="note-block">
        <p class="note-label">Napomena:</p>
        <div class="note-box">{{ data_get($snapshot, 'note') }}</div>
    </div>

    <section class="signature-section">
        <table class="signature-table">
            <tr>
                <td>
                    <div class="signature-heading">PRODAVATELJ</div>
                    <div class="signature-space"></div>
                    <div class="signature-line">(vlastoručni potpis)</div>
                    <div class="signature-oib">OIB: <strong>{{ $fill(data_get($snapshot, 'seller_oib')) }}</strong></div>
                </td>
                <td>
                    <div class="signature-heading">KUPAC</div>
                    <div class="signature-space"></div>
                    <div class="signature-line">(vlastoručni potpis)</div>
                    <div class="signature-oib">OIB: <strong>{{ $fill(data_get($snapshot, 'buyer_oib')) }}</strong></div>
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
