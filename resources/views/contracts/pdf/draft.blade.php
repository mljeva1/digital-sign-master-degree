<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <title>Kupoprodajni ugovor - probni PDF</title>
    <style>
        @page { margin: 32px; }
        body {
            color: #111827;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.45;
        }
        h1 { margin: 0; font-size: 22px; text-align: center; }
        h2 { margin: 24px 0 8px; font-size: 14px; border-bottom: 1px solid #94a3b8; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 7px; text-align: left; vertical-align: top; }
        th { width: 34%; background: #f1f5f9; }
        .draft {
            margin: 14px 0 20px;
            border: 2px solid #b91c1c;
            color: #b91c1c;
            font-size: 16px;
            font-weight: bold;
            padding: 10px;
            text-align: center;
        }
        .meta { margin-bottom: 18px; color: #475569; text-align: center; }
        .notice {
            margin-top: 28px;
            border: 1px solid #b91c1c;
            background: #fef2f2;
            color: #7f1d1d;
            padding: 12px;
            font-weight: bold;
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
    @endphp

    <h1>Kupoprodajni ugovor</h1>
    <div class="draft">DRAFT / PROBNI PDF</div>
    <div class="meta">
        ID ugovora: {{ $contract->id }}<br>
        Datum generiranja: {{ $generatedAt->format('d.m.Y. H:i:s') }}
    </div>

    <h2>Ugovorne strane</h2>
    <table>
        <tr><th>Prodavatelj</th><td>{{ $display(data_get($snapshot, 'seller_name')) }}</td></tr>
        <tr><th>Adresa prodavatelja</th><td>{{ $display(data_get($snapshot, 'seller_address')) }}</td></tr>
        <tr><th>OIB prodavatelja</th><td>{{ $display(data_get($snapshot, 'seller_oib')) }}</td></tr>
        <tr><th>Kupac</th><td>{{ $display(data_get($snapshot, 'buyer_name')) }}</td></tr>
        <tr><th>Adresa kupca</th><td>{{ $display(data_get($snapshot, 'buyer_address')) }}</td></tr>
        <tr><th>OIB kupca</th><td>{{ $display(data_get($snapshot, 'buyer_oib')) }}</td></tr>
    </table>

    <h2>Podaci o ugovoru</h2>
    <table>
        <tr><th>Mjesto sklapanja</th><td>{{ $display(data_get($snapshot, 'place')) }}</td></tr>
        <tr><th>Datum ugovora</th><td>{{ $display(data_get($snapshot, 'contract_date')) }}</td></tr>
        <tr><th>Nadležni sud</th><td>{{ $display(data_get($snapshot, 'court_place')) }}</td></tr>
        <tr><th>Prodajna cijena</th><td>{{ $display(data_get($snapshot, 'price_amount')) }} EUR</td></tr>
        <tr><th>Iznos riječima</th><td>{{ $display(data_get($snapshot, 'price_words')) }}</td></tr>
    </table>

    <h2>Vozilo</h2>
    <table>
        <tr><th>Vozilo</th><td>{{ $display($vehicle) }}</td></tr>
        <tr><th>Registracija</th><td>{{ $display(data_get($snapshot, 'registration_number')) }}</td></tr>
        <tr><th>VIN</th><td>{{ $display(data_get($snapshot, 'vin')) }}</td></tr>
        <tr><th>Boja</th><td>{{ $display(data_get($snapshot, 'vehicle_color')) }}</td></tr>
        <tr><th>Godina proizvodnje</th><td>{{ $display(data_get($snapshot, 'production_year')) }}</td></tr>
        <tr><th>Prva registracija</th><td>{{ $display(data_get($snapshot, 'first_registration_date')) }}</td></tr>
        <tr><th>Motor</th><td>{{ $display(data_get($snapshot, 'engine_type')) }}</td></tr>
        <tr><th>Snaga / obujam</th><td>{{ $display(data_get($snapshot, 'engine_power_kw')) }} kW / {{ $display(data_get($snapshot, 'engine_displacement_cc')) }} cm³</td></tr>
    </table>

    <h2>Plaćanje i napomene</h2>
    <table>
        <tr><th>Isplaćeni iznos</th><td>{{ $display(data_get($snapshot, 'paid_amount')) }} EUR</td></tr>
        <tr><th>Ostatak</th><td>{{ $display(data_get($snapshot, 'remaining_amount')) }} EUR</td></tr>
        <tr><th>Ostatak riječima</th><td>{{ $display(data_get($snapshot, 'remaining_words')) }}</td></tr>
        <tr><th>Rok plaćanja ostatka</th><td>{{ $display(data_get($snapshot, 'remaining_due_date')) }}</td></tr>
        <tr><th>Predane stvari</th><td>{{ $display(data_get($snapshot, 'included_items')) }}</td></tr>
        <tr><th>Troškove snosi</th><td>{{ $display(data_get($snapshot, 'costs_paid_by')) }}</td></tr>
        <tr><th>Napomena</th><td>{{ $display(data_get($snapshot, 'note')) }}</td></tr>
    </table>

    <div class="notice">
        Ovaj dokument je probni draft za pregled. Nije digitalno potpisan i nije finaliziran.
    </div>
</body>
</html>
