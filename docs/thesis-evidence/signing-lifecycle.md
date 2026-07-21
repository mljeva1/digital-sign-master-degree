# Signing lifecycle — dokazna građa

Detached CMS/PKCS#7 potpis nad zamrznutim finalnim PDF-om. Native PHP `ext-openssl`
(`openssl_cms_sign` / `openssl_cms_verify`), bez CLI-a i bez string concatenationa.

## Sequence (potpisivanje)

```mermaid
sequenceDiagram
    participant U as Owner (browser)
    participant CSC as ContractSignatureController
    participant CSS as ContractSigningService
    participant FIV as FinalPdfIntegrityVerifier
    participant DCS as DetachedCmsSigner
    participant DCV as DetachedCmsVerifier
    participant DB as PostgreSQL
    participant FS as Privatni disk

    U->>CSC: POST /contracts/{id}/sign (CSRF, throttle 6/1)
    CSC->>CSC: abort_unless owner (prije servisa)
    CSC->>CSS: sign(contractId) — actor iz auth guarda
    CSS->>FIV: preflight (finalized+locked, exact final-PDF integritet)
    CSS->>DB: obavezna aktivna javna provjera + exact token→PDF/QR proof
    CSS->>DB: jedan aktivni signer certifikat (inače AMBIGUOUS)
    CSS->>FS: čita zamrznuti PDF + signer materijal
    CSS->>DCS: openssl_cms_sign (detached DER, izvan DB transakcije)
    DCS->>DCV: neposredna openssl_cms_verify (crypto + trust odvojeno)
    CSS->>FS: write .p7s (unique path) + fizička re-verifikacija
    CSS->>DB: kratka zaključana transakcija User(owner)→Contract
    DB-->>CSS: CMS StoredFile + completed Signature + signing audit
    CSS-->>CSC: rezultat (stabilni ID-evi, source SHA-256, fingerprint)
    CSC-->>U: signed UI (download link, bez regeneracijskih gumba)
```

## Verifikacija (dva native poziva)

```mermaid
flowchart TD
    A[Zamrznuti PDF bajtovi] --> V1[openssl_cms_verify NOVERIFY]
    S[.p7s CMS artefakt] --> V1
    V1 --> C1[cryptographic_valid + signer fingerprint]
    A --> V2[openssl_cms_verify protiv Root CA]
    S --> V2
    V2 --> C2[trust_valid]
    C1 --> R{Odvojeni signali}
    C2 --> R
    R --> O[cryptographic / trust / certificate_time / certificate_active / signer_fingerprint / source_hash]
```

`OPENSSL_CMS_NOVERIFY` se koristi **samo** za izolaciju crypto signala i izvlačenje signer
certifikata — nikad kao trust rezultat.

## Invarijante (dokazano testovima)

- `document_hash_before == document_hash_after` (isti SHA-256 potpisanog PDF-a); CMS artefakt
  ima vlastiti `StoredFile` hash.
- `source_file_id == contracts.final_pdf_file_id` (exact binding; aplikacijski ugovor).
- Freeze-before-sign: nema regeneracije PDF/QR nakon potpisa.
- Partial unique `signatures_contract_user_source_active_unique` — fizički dokazan na
  PostgreSQL-u (blokira drugi pending, completed-when-pending, drugi completed).
- Negativni scenariji: tamper PDF/CMS bajta, wrong Root CA, wrong key, wrong passphrase,
  neaktivan/istekao/not-yet-valid cert — svi odbijeni.

## Granica tvrdnji

Lokalna testna CMS/PKCS#7 demonstracija sa self-signed testnim trust anchorom. Nije
PAdES/CAdES/eIDAS/AdES/QES/QSCD/QTSP; nije embedded (detached); nema pravnu snagu ni
non-repudiation. Potpisnik je tehnički vlasnik zapisa (`signed_user_id`), nikad dokaz da je
prodavatelj/kupac potpisao. Shared-key model: korisnik ne posjeduje privatni ključ.
