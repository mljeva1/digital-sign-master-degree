# Signing and verification — puni workflow i sigurnosne granice

Lokalni akademski detached CMS/PKCS#7 potpis nad zamrznutim finalnim PDF-om. Provjera kroz
PHP `ext-openssl`. **Nije** PAdES/eIDAS/QES i nema pravnu snagu.

## Local signer provisioning

Radi samo u `local`/`testing`; u produkciji odbijeno.

```bash
php artisan signing:provision-local-signer <USER_ID>
```

Kreira (u `storage/app/private/signing/local`, gitignored) — samo nazivi/purpose:

| Datoteka | Uloga |
|---|---|
| `test-root-ca.pem` | lokalni testni Root CA certifikat (trust anchor) |
| `test-root-ca-key.pem` | provisioning-only CA privatni ključ (nikad u signing runtimeu) |
| `test-signer-cert.pem` | javni signer certifikat |
| `test-signer-key.pem` | signer privatni ključ (zajednički za sve korisnike) |
| `test-signer-passphrase.txt` | passphrase datoteka |

Naredba registrira `certificates` redak (`is_active = true`) vezan uz korisnika kroz
`SignerCertificateRegistrar`. Ispis ne otkriva ključ, passphrase ni apsolutne putanje.

**Shared-key model:** jedan zajednički privatni ključ, per-user certifikat. Korisnik ne
posjeduje ključ; identitet potpisnika proizlazi iz autentikacije/autorizacije/binding/audita.
Bez non-repudiationa.

## Puni tok

```mermaid
flowchart TD
    P[Profile podaci] --> B[Contract builder]
    B --> S[Snapshot JSONB]
    S --> F[Finalizacija: status + locked_at + SHA-256]
    F --> FP[Final PDF create-only, privatno]
    FP --> PV[Public verification: token + QR, exact proof]
    PV --> FRZ[Freeze final PDF bytes]
    FRZ --> CMS[Detached CMS/PKCS#7 potpis openssl_cms_sign]
    CMS --> VER[Neposredna verifikacija openssl_cms_verify]
    VER --> ART[.p7s → cms_signature StoredFile → completed Signature → audit]
    ART --> DL[Owner-only .p7s download]
    ART --> PUB[Perzistirani javni prikaz potpisnog statusa]
```

### Ključne kontrole

- **Freeze-before-sign:** nakon što potpis postoji, final PDF i QR se **ne** regeneriraju
  (`FinalPdfIntegrityVerifier::assertNotActivelySigned()`), prije rendera i prije writea.
- **Exact source binding:** `source_file_id == contracts.final_pdf_file_id`; integritet
  PDF-a re-verificira se pod Contract lockom.
- **Exact token→PDF/QR proof:** signing traži aktivnu javnu provjeru (token, `enabled_at`,
  `revoked_at IS NULL`) i persisted proof (contract + file id + PDF SHA-256 +
  `generation_reason` + token SHA-256 + URL SHA-256). Timestamp nije dokaz.
- **Detached, nikad embedded:** `.p7s` je zaseban `cms_signature` `StoredFile`;
  `document_hash_before == document_hash_after`; PDF ni CMS artefakt se nikad ne
  overwriteaju.
- **Idempotency / concurrency:** DB partial unique `signatures_contract_user_source_active_unique`
  (`contract_id`, `signed_user_id`, `source_file_id`) je last-resort guard; klasifikator
  traži exact SQLSTATE `23505` + exact ime constrainta. Lock order `User(owner) → Contract`.

## Rute

| Metoda / ruta | Ime | Napomena |
|---|---|---|
| `POST /contracts/{contract}/sign` | `contracts.sign.store` | owner-only, CSRF, `throttle:6,1`, actor iz auth guarda |
| `GET /contracts/{contract}/signature/download` | `contracts.signature.download` | owner-only `.p7s`, exact binding + purpose/disk/size/SHA-256 prije slanja |
| `GET /verify/contracts/{token}` | `public.contracts.verify.show` | jedini javni contract endpoint, `throttle:20,1` (ključ = IP, nikad token) |

## Javni prikaz potpisnog statusa (8 odvojenih signala)

Read-only i fail-closed. Odvojeno prikazuje: integritet finalnog PDF-a, integritet potpisnog
artefakta, kriptografsku provjeru, povjerenje (lokalni testni Root CA), vremensku valjanost
certifikata, aktivnost certifikata, podudarnost otiska potpisnika, podudarnost source hasha.
Bez potpisa: neutralno „Dokument još nema dovršen digitalni potpis." Kad provjeru nije moguće
izvršiti, potpis se **ne** prikazuje kao valjan.

**Javna granica:** stranica ne otkriva certifikatni sadržaj, subject/issuer DN, serial,
privatnu putanju, download URL, token, PII ni PDF sadržaj. Prikazuje se samo **SHA-256
otisak** potpisnog certifikata (digest, ne tajna).

## Sigurnosne granice (sažetak)

- Privatna pohrana; owner authorization prije servisa; actor nikad iz requesta.
- Audit allow-list (`contract.signature_completed`, `contract.cms_signature_downloaded`):
  samo stabilni ID-evi i `*_sha256` digesti; nikad token/path/DN/serial/ključ/raw error.
- Certificate ambiguity (2+ aktivna) → fail-closed, bez gumba, bez metadata.
- Nikad ne tvrditi PAdES/eIDAS/QES/AdES/QSCD/QTSP, pravnu valjanost ni non-repudiation.

## Certificate request and controlled issuance (M14)

M14 razdvaja **traženje** certifikata od **izdavanja**. Korisnik podnese zahtjev,
`certificate_operator` odobri, approval atomski (isti `database` queue, isti connection,
`after_commit=false`) upiše jedan issuance job, a **dedicated worker**
(`queue:work database --queue=certificate-issuance --tries=3`) izda per-user leaf certifikat.

- **Zajednički servis, dvije sposobnosti:** `LocalSignerCertificateIssuanceService` dijeli
  per-user leaf issuance između provisioning naredbe i workera. Samo naredba smije
  bootstrapati/oporaviti Root CA + ključeve + passphrase; worker izdaje **isključivo** iz
  postojećeg, strukturno potvrđenog roota i fail-closed odbija nepotpun/nesiguran/nevaljan root.
- **Attempt-owned artefakt + ownership (P2-2):** worker claima leaf **exclusive-create**
  putanjom izvedenom iz request ID-a i attempt UUID-a unutar potvrđenog roota. Rezultat je
  `AttemptCertificateArtifact` (path samo interno, `created_by_current_invocation`). Pobjednik
  (created=true) smije kasnije cleanupati **samo** nakon re-provjere iste putanje i istog
  content-hasha; gubitnik create-utrke (created=false) re-validira i **ponovno koristi**
  pobjednikov certifikat i **nikad** ga ne briše. Djelomično zapisan artefakt → transient retry
  bez unlinka; nevaljan/nesiguran postojeći artefakt → fail-closed bez prepisivanja/brisanja.
- **Per-file shared-material safety (P2-4):** svaka od četiri shared datoteke (Root CA cert, Root
  CA key, signer key, passphrase) prolazi zasebnu fail-closed provjeru neposredno prije čitanja
  (allow-listed basename, canonical parent unutar potvrđenog roota, bez symlink/junction/reparse,
  regularna datoteka dokazana `lstat` i post-open `fstat`), pa tek onda čita iz otvorenog handlea.
- **Transient vs permanent (P2-3):** jedan zatvoreni `TransientDatabaseFailureClassifier` prolazi
  cijeli exception chain (uklj. `getPrevious()`, `QueryException`/`PDOException`, `errorInfo[0]`) i
  transient su **samo** SQLSTATE `40001`/`40P01`/`55P03` te dopušteni SQLite „database is locked".
  Registrar zadržava original u `previous` chainu (raw poruka nikad ne ide u DB/audit/UI/session).
- **Exact queue connection (P2-6):** `IssueCertificateJob` je pinan na
  `onConnection('database')` + `onQueue('certificate-issuance')`; `IssuanceQueueContract` provjerava
  **točno** `queue.connections.database` (driver `database`, isti domain/fizički connection,
  `after_commit=false`), nikad „bilo koji database-driver connection" ni `queue.default`.
- **Atomski completion seam:** `SignerCertificateRegistrar` unutar iste persistence transakcije
  kao `StoredFile` + `Certificate` fresh-locka `CertificateRequest`, re-dokaže exact
  request/attempt/user/state, veže `certificate_id`, prebaci `issuing → issued` i upiše
  `certificate.issuance.completed`. Nema commitanog `Certificate` bez dovršenog bindinga, ni
  `issued` zahtjeva bez valjanog `Certificate`. Isti seam vrijedi i na exact-fingerprint
  idempotent recovery putu.
- **Root CA key granica:** učitavaju ga samo bootstrap naredba, worker i njihov zajednički
  servis — nikad HTTP/Form Request/Blade/CMS document-signing/javna provjera/queue serializer/
  audit/logger, i nije `config/signing.php` runtime opcija.
- **Audit (`certificate.issuance.started|completed|failed`):** actor je konkretni operater
  (`reviewed_by_user_id`), `failed` je `success=false`, metadata je stroga allow-lista bez
  napomene, PII-ja, DN-a, seriala, attempt UUID-a, putanje, PEM-a ni raw errora.
- **Failure/retry:** trajni failure → terminalni `failed` samo iz `issuing`, stabilni
  `failure_code`, bez raw errora; prolazni lock/deadlock → retry istog attempta bez terminalnog
  zapisa; iscrpljeni retryji → `ISSUANCE_RETRIES_EXHAUSTED`.

**M14 nije** produkcijski CA, QTSP, PAdES, eIDAS, QES, QSCD, kvalificirani certifikat, pravno
valjan potpis ni non-repudiation — to je lokalni akademski PKI tok.

## Poznata ograničenja (P3)

- Stvarni paralelni PostgreSQL race test nije izveden (vidi [testing.md](testing.md)).
- Concurrent filesystem-swap TOCTOU residual; Windows ACL nad signing rootom; `serve => true`
  storage ruta bez temporary URL-a; shared local-test key (namjerno); provisioning-only CA
  key na disku; stari `dsmd-dev-signer` cleanup. Sve namjerno izvan M13 scopea.
- **M14:** hard-crash nakon zapisa attempt/leaf datoteke a prije DB commita može ostaviti orphan
  javnog certificate artefakta (create-only, attempt-owned putanja) — P3 residual za budući
  reconciliation scan; ne može proizvesti lažni `issued` ni drugi `Certificate`. Dublji
  dvo-konekcijski PostgreSQL worker-race dokaz **nije izvršen** u ovom prolazu. Random serial nema
  trajnu **attempt→fingerprint provenance** vezu, a globalni exact-fingerprint recovery nema
  eksplicitnu **request provenance** vezu — oboje ostaju **P3** (bez nove DB provenance migracije);
  ownership cleanup (P2-2) svejedno jamči da gubitnik nikad ne briše pobjednikov artefakt.
  Windows ACL/process-isolation temp/artefakt residual ostaje P3.
