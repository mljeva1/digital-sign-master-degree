<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Javna provjera dokumenta | Digital Sign Master Degree</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #020617;
            color: #e2e8f0;
            font-family: Arial, sans-serif;
            line-height: 1.5;
        }
        main { max-width: 760px; margin: 0 auto; padding: 40px 20px; }
        header { border-bottom: 1px solid #1e293b; padding-bottom: 28px; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 10px; color: #fff; font-size: 30px; }
        h2 { margin-bottom: 8px; color: #fff; font-size: 19px; }
        .eyebrow {
            margin-bottom: 8px;
            color: #a5f3fc;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 3px;
        }
        .muted { color: #94a3b8; font-size: 14px; }
        .card {
            margin-top: 24px;
            border: 1px solid #1e293b;
            border-radius: 20px;
            background: #0f172a;
            padding: 24px;
        }
        .status-card { border-color: #14532d; background: #052e16; }
        .status { color: #bbf7d0; font-weight: 700; }
        dl { margin: 22px 0 0; }
        dt { margin-top: 14px; color: #64748b; font-size: 13px; }
        dd { margin: 4px 0 0; color: #e2e8f0; }
        .hash {
            overflow-wrap: anywhere;
            color: #a5f3fc;
            font-family: Consolas, monospace;
            font-size: 12px;
        }
        .file-input {
            display: block;
            width: 100%;
            margin-top: 18px;
            border: 1px solid #334155;
            border-radius: 12px;
            background: #020617;
            color: #cbd5e1;
            padding: 12px;
        }
        button {
            margin-top: 12px;
            border: 1px solid #22d3ee;
            border-radius: 10px;
            background: #164e63;
            color: #cffafe;
            cursor: pointer;
            font-weight: 700;
            padding: 10px 16px;
        }
        button:disabled { cursor: wait; opacity: .65; }
        .hash-result {
            margin-top: 18px;
            border-top: 1px solid #1e293b;
            padding-top: 16px;
        }
        .result {
            display: none;
            margin-top: 14px;
            border: 1px solid;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
        }
        .result.success { display: block; border-color: #166534; background: #052e16; color: #bbf7d0; }
        .result.error { display: block; border-color: #991b1b; background: #450a0a; color: #fecaca; }
        .notice {
            margin-top: 24px;
            border: 1px solid #78350f;
            border-radius: 16px;
            background: #451a03;
            color: #fde68a;
            padding: 16px 18px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <main>
        <header>
            <p class="eyebrow">DSMD · VERIFY</p>
            <h1>Javna provjera dokumenta</h1>
            <p class="muted">
                Stranica potvrđuje status zapisa i službene SHA-256 vrijednosti.
                Ne prikazuje sadržaj ugovora niti omogućuje preuzimanje privatnog PDF-a.
            </p>
        </header>

        <section class="card status-card">
            <p class="status">STATUS PROVJERE</p>
            <h2>Dokument je evidentiran kao finaliziran</h2>

            <dl>
                <dt>Vrijeme finalizacije</dt>
                <dd>{{ $contract->finalized_at?->format('d.m.Y. H:i:s') ?? 'N/A' }}</dd>

                <dt>SHA-256 finalnog PDF-a</dt>
                <dd id="officialPdfHash" class="hash">{{ $contract->final_pdf_sha256 ?? 'Nije dostupno' }}</dd>

                <dt>SHA-256 zaključanog snapshota</dt>
                <dd class="hash">{{ $contract->finalized_snapshot_sha256 }}</dd>
            </dl>
        </section>

        <section class="card" id="localPdfVerification">
            <h2>Provjera PDF datoteke</h2>
            <p class="muted">
                Odaberite lokalnu PDF datoteku. Hash se računa u pregledniku pomoću
                Web Crypto API-ja i datoteka se ne šalje na server.
            </p>

            <input
                id="pdfFile"
                class="file-input"
                type="file"
                accept=".pdf,application/pdf"
                aria-describedby="fileVerificationResult"
            >
            <button id="verifyFileButton" type="button">Provjeri datoteku</button>

            <dl class="hash-result">
                <dt>Izračunati SHA-256</dt>
                <dd id="calculatedPdfHash" class="hash">Datoteka još nije provjerena.</dd>

                <dt>Službeni SHA-256</dt>
                <dd class="hash">{{ $contract->final_pdf_sha256 ?? 'Nije dostupno' }}</dd>
            </dl>

            <div id="fileVerificationResult" class="result" role="status" aria-live="polite"></div>
        </section>

        <div class="notice">
            Ova provjera potvrđuje podudaranje hash vrijednosti i status zapisa.
            Ovo nije kriptografski digitalni potpis.
        </div>
    </main>

    <script>
        const fileInput = document.getElementById('pdfFile');
        const verifyButton = document.getElementById('verifyFileButton');
        const officialHash = document.getElementById('officialPdfHash').textContent.trim().toLowerCase();
        const calculatedHash = document.getElementById('calculatedPdfHash');
        const result = document.getElementById('fileVerificationResult');

        const showResult = (message, type) => {
            result.textContent = message;
            result.className = `result ${type}`;
        };

        verifyButton.addEventListener('click', async () => {
            const file = fileInput.files[0];
            const isPdf = file
                && (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf'));

            if (! isPdf) {
                calculatedHash.textContent = 'Nije izračunato.';
                showResult('Odaberite PDF datoteku.', 'error');

                return;
            }

            if (! /^[a-f0-9]{64}$/.test(officialHash)) {
                showResult('Službeni SHA-256 nije dostupan.', 'error');

                return;
            }

            verifyButton.disabled = true;

            try {
                const arrayBuffer = await file.arrayBuffer();
                const digest = await crypto.subtle.digest('SHA-256', arrayBuffer);
                const actualHash = Array.from(new Uint8Array(digest))
                    .map((byte) => byte.toString(16).padStart(2, '0'))
                    .join('');

                calculatedHash.textContent = actualHash;
                showResult(
                    actualHash === officialHash
                        ? 'Datoteka odgovara službenom hash zapisu.'
                        : 'Datoteka ne odgovara službenom hash zapisu.',
                    actualHash === officialHash ? 'success' : 'error'
                );
            } catch (error) {
                calculatedHash.textContent = 'Nije izračunato.';
                showResult('Hash datoteke nije moguće izračunati u ovom pregledniku.', 'error');
            } finally {
                verifyButton.disabled = false;
            }
        });
    </script>
</body>
</html>
