<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Javna provjera dokumenta | Digital Sign Master Degree</title>
    {{-- Intentionally self-contained: no external CDN or network request on a
         public page. Styling is inline; the only script hashes the user's local
         file in-browser via the Web Crypto API and never uploads anything. --}}
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #020617;
            color: #e2e8f0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            line-height: 1.55;
            -webkit-text-size-adjust: 100%;
        }
        main { max-width: 760px; margin: 0 auto; padding: 40px 20px; }
        header { border-bottom: 1px solid #1e293b; padding-bottom: 26px; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 10px; color: #fff; font-size: 30px; }
        h2 { margin-bottom: 8px; color: #fff; font-size: 19px; }
        a { color: #67e8f9; }
        .eyebrow {
            margin: 0 0 10px; color: #a5f3fc; font-size: 12px;
            font-weight: 700; letter-spacing: 3px;
        }
        .muted { color: #94a3b8; font-size: 14px; }
        .pill {
            display: inline-flex; align-items: center; gap: 8px;
            border: 1px solid #14532d; border-radius: 999px;
            background: #052e16; color: #bbf7d0;
            padding: 6px 14px; font-size: 13px; font-weight: 700;
        }
        .pill::before { content: ""; width: 8px; height: 8px; border-radius: 999px; background: #4ade80; }
        .card {
            margin-top: 24px; border: 1px solid #1e293b; border-radius: 20px;
            background: #0f172a; padding: 24px;
        }
        .status-card { border-color: #14532d; background: #052e16; }
        .status { margin: 0 0 12px; color: #bbf7d0; font-weight: 700; letter-spacing: 2px; font-size: 12px; }
        dl { margin: 22px 0 0; }
        dt { margin-top: 14px; color: #64748b; font-size: 13px; }
        dd { margin: 4px 0 0; color: #e2e8f0; }
        .hash {
            overflow-wrap: anywhere; color: #a5f3fc;
            font-family: Consolas, ui-monospace, monospace; font-size: 12px;
        }
        .file-input {
            display: block; width: 100%; min-height: 44px; margin-top: 18px;
            border: 1px solid #334155; border-radius: 12px;
            background: #020617; color: #cbd5e1; padding: 12px; font-size: 16px;
        }
        button {
            min-height: 44px; margin-top: 14px;
            border: 1px solid #22d3ee; border-radius: 12px;
            background: #164e63; color: #cffafe; cursor: pointer;
            font-weight: 700; padding: 10px 18px; font-size: 15px;
        }
        button:hover { background: #155e75; }
        button:disabled { cursor: wait; opacity: .65; }
        :focus-visible { outline: 2px solid #67e8f9; outline-offset: 2px; }
        .hash-result { margin-top: 18px; border-top: 1px solid #1e293b; padding-top: 16px; }
        .result {
            display: none; margin-top: 14px; border: 1px solid; border-radius: 12px;
            padding: 12px 14px; font-size: 14px; font-weight: 600;
        }
        .result.success { display: block; border-color: #166534; background: #052e16; color: #bbf7d0; }
        .result.error { display: block; border-color: #991b1b; background: #450a0a; color: #fecaca; }
        .notice {
            margin-top: 24px; border: 1px solid #78350f; border-radius: 16px;
            background: #451a03; color: #fde68a; padding: 16px 18px; font-size: 14px;
        }
        .signal-list { margin: 16px 0 0; padding: 0; list-style: none; display: grid; gap: 8px; }
        .signal-list li {
            display: flex; justify-content: space-between; gap: 12px; align-items: baseline;
            border: 1px solid #1e293b; border-radius: 10px; background: #020617;
            padding: 9px 12px; font-size: 13px;
        }
        .signal-ok { color: #86efac; font-weight: 700; }
        .signal-fail { color: #fca5a5; font-weight: 700; }
        .sig-neutral {
            margin-top: 14px; border: 1px solid #334155; border-radius: 12px;
            background: #0b1120; color: #cbd5e1; padding: 12px 14px; font-size: 14px;
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
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
            <span class="pill">Dokument je evidentiran kao finaliziran</span>

            <dl>
                <dt>Vrijeme finalizacije</dt>
                <dd>{{ $contract->finalized_at?->format('d.m.Y. H:i:s') ?? 'N/A' }}</dd>

                <dt>SHA-256 finalnog PDF-a</dt>
                <dd id="officialPdfHash" class="hash">{{ $contract->final_pdf_sha256 ?? 'Nije dostupno' }}</dd>

                <dt>SHA-256 zaključanog snapshota</dt>
                <dd class="hash">{{ $contract->finalized_snapshot_sha256 }}</dd>
            </dl>
        </section>

        <section class="card" id="signatureStatus">
            <h2>Digitalni potpis</h2>
            @if (! $signatureStatus->signaturePresent)
                <p class="sig-neutral">Dokument još nema dovršen digitalni potpis.</p>
            @elseif ($signatureStatus->verificationUnavailable)
                <p class="sig-neutral">
                    Zabilježen je dovršen digitalni potpis, ali provjeru potpisa trenutno nije
                    moguće izvršiti. Potpis se ne prikazuje kao valjan dok provjera ne uspije.
                </p>
            @else
                <p class="muted">
                    Svaki signal provjere prikazuje se odvojeno. Provjera koristi isključivo
                    pohranjene zapise i lokalni testni trust anchor.
                </p>
                <ul class="signal-list">
                    <li>
                        <span>Integritet finalnog PDF-a</span>
                        <span class="{{ $signatureStatus->pdfIntegrityValid ? 'signal-ok' : 'signal-fail' }}">{{ $signatureStatus->pdfIntegrityValid ? 'Valjan' : 'Nije valjan' }}</span>
                    </li>
                    <li>
                        <span>Integritet potpisnog artefakta</span>
                        <span class="{{ $signatureStatus->cmsIntegrityValid ? 'signal-ok' : 'signal-fail' }}">{{ $signatureStatus->cmsIntegrityValid ? 'Valjan' : 'Nije valjan' }}</span>
                    </li>
                    <li>
                        <span>Kriptografska provjera potpisa</span>
                        <span class="{{ $signatureStatus->cryptographicValid ? 'signal-ok' : 'signal-fail' }}">{{ $signatureStatus->cryptographicValid ? 'Uspješna' : 'Neuspješna' }}</span>
                    </li>
                    <li>
                        <span>Povjerenje (lokalni testni Root CA)</span>
                        <span class="{{ $signatureStatus->trustValid ? 'signal-ok' : 'signal-fail' }}">{{ $signatureStatus->trustValid ? 'Potvrđeno' : 'Nije potvrđeno' }}</span>
                    </li>
                    <li>
                        <span>Vremenska valjanost certifikata</span>
                        <span class="{{ $signatureStatus->certificateTimeValid ? 'signal-ok' : 'signal-fail' }}">{{ $signatureStatus->certificateTimeValid ? 'Valjana' : 'Nije valjana' }}</span>
                    </li>
                    <li>
                        <span>Certifikat aktivan u evidenciji</span>
                        <span class="{{ $signatureStatus->certificateActive ? 'signal-ok' : 'signal-fail' }}">{{ $signatureStatus->certificateActive ? 'Da' : 'Ne' }}</span>
                    </li>
                    <li>
                        <span>Podudaranje potpisnog certifikata</span>
                        <span class="{{ $signatureStatus->signerFingerprintMatches ? 'signal-ok' : 'signal-fail' }}">{{ $signatureStatus->signerFingerprintMatches ? 'Da' : 'Ne' }}</span>
                    </li>
                    <li>
                        <span>Podudaranje potpisanog sadržaja</span>
                        <span class="{{ $signatureStatus->sourceHashMatches ? 'signal-ok' : 'signal-fail' }}">{{ $signatureStatus->sourceHashMatches ? 'Da' : 'Ne' }}</span>
                    </li>
                </ul>

                <dl>
                    <dt>Vrijeme potpisa</dt>
                    <dd>{{ $signatureStatus->signedAtIso ? \Illuminate\Support\Carbon::parse($signatureStatus->signedAtIso)->format('d.m.Y. H:i:s') : 'N/A' }}</dd>

                    @if ($signatureStatus->certificateFingerprint)
                        <dt>SHA-256 otisak potpisnog certifikata</dt>
                        <dd class="hash">{{ $signatureStatus->certificateFingerprint }}</dd>
                    @endif
                </dl>
            @endif

            <p class="muted" style="margin-top: 16px;">
                Digitalni potpis u ovom sustavu lokalni je akademski X.509 detached CMS/PKCS#7
                prototip sa self-signed testnim trust anchorom — nije PAdES, eIDAS niti
                kvalificirani elektronički potpis i nema pravnu snagu.
            </p>
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
