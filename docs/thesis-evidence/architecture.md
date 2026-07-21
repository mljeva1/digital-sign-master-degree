# Arhitektura — dokazna građa

Činjenice potvrđene iz koda (`app/`, `routes/web.php`, `config/`). Runtime baza je
PostgreSQL; SQLite je samo test harness.

## Runtime slojevi

| Sloj | Odgovornost |
|---|---|
| `AuthController` | Register/login/logout; register dodjeljuje `employee` role |
| `ContractController` | Owner-scoped builder/snapshot/draft PDF/finalizacija/final PDF/public verify enable/audit |
| `ContractSignatureController` | Owner-only signing POST (CSRF, `throttle:6,1`) i owner-only `.p7s` download; bez DB transakcije, bez OpenSSL poziva, bez PDF regeneracije |
| `PublicContractVerificationController` | Jedini javni contract endpoint; token + finalized/locked/enabled/not-revoked filter; read-only prikaz potpisnog statusa; `throttle:20,1` |
| `VehicleCatalogController` | Auth-only throttled read endpoint s JSON whitelistom |
| `DocumentController` | Owner/purpose-scoped generički upload modul |
| `ContractSigningService` | Orkestracija potpisa: preflight → CMS izvan transakcije → `.p7s` write → kratka zaključana transakcija |
| `DetachedCmsSigner` / `DetachedCmsVerifier` | Native detached DER CMS preko PHP `ext-openssl` |
| `AuditLogger` | Audit zapis + rekurzivna key-name sanitizacija |
| `FinalPdfGenerator` | Create-only privatni final PDF; jedini top-level transaction owner |

## Komponente (Mermaid)

```mermaid
flowchart LR
    subgraph HTTP
      CC[ContractController]
      CSC[ContractSignatureController]
      PVC[PublicContractVerificationController]
    end
    subgraph Services
      CSS[ContractSigningService]
      FPG[FinalPdfGenerator]
      FIV[FinalPdfIntegrityVerifier]
      DCS[DetachedCmsSigner]
      DCV[DetachedCmsVerifier]
      AL[AuditLogger]
    end
    subgraph DB[(PostgreSQL)]
      C[(contracts)]
      SG[(signatures)]
      CE[(certificates)]
      FL[(files / StoredFile)]
      AE[(audit_events)]
    end
    subgraph Storage[Privatni disk local]
      PDF[final PDF]
      P7S[.p7s CMS artefakt]
      KEY[signer materijal gitignored]
    end

    CC --> FPG --> C
    FPG --> FL
    FPG --> PDF
    CSC --> CSS
    CSS --> FIV
    CSS --> DCS --> DCV
    CSS --> SG
    CSS --> FL
    CSS --> P7S
    CSS --> AL --> AE
    PVC --> DCV
    DCS -.reads.-> KEY
```

## Aggregate i storage

- `Contract` je aktivni aggregate root; `filled_data_snapshot` (JSONB) je jedini izvor
  istine za sadržaj ugovora. `contract_parties` postoji kao shema, ali nije u aktivnom
  write toku.
- Aktivni disk je `local`, root `storage/app/private`. Final PDF je **create-only**:
  `contracts/{id}/final-pdfs/final-{uuid}.pdf`; aktivni artefakt određuje
  `contracts.final_pdf_file_id`. CMS potpis: `contracts/{id}/signatures/sig-{uuid}.p7s`.
- Signer materijal (local/testing): `storage/app/private/signing/local` (gitignored).

## Ograničenja / granica tvrdnji

- CMS je lokalna testna PKCS#7 demonstracija sa self-signed testnim Root CA — nije
  PAdES/eIDAS/QES.
- Shared-key model: jedan zajednički privatni ključ, per-user certifikat; bez
  non-repudiationa.
- Audit je append-only na Eloquent razini, ne na DB razini (nema trigger/permission).
