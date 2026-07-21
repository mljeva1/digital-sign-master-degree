# Chapter evidence map

Mapiranje poglavlja diplomskog rada na potvrđene činjenice iz koda, commitove, testne klase,
preporučene vizualne dokaze, ograničenja i zabranjene tvrdnje. Namjena: autor rada zna gdje je
dokaz i što **ne smije** tvrditi.

Zajedničke zabranjene tvrdnje (vrijede za sva poglavlja): MySQL kao aktualni DB; SQLite kao
runtime DB; PAdES/embedded PDF potpis; eIDAS/QES/AdES/QSCD/QTSP; osobno posjedovanje privatnog
ključa; non-repudiation; pravna valjanost.

---

## 1. Uvod

- **Činjenice:** cilj — lokalni akademski tok izrade i kriptografskog potpisivanja
  kupoprodajnog ugovora o vozilu; opseg local/testing, bez produkcije.
- **Commitovi:** cijela linija `1183c2b` → `1f23659` → `d5fe4ab` (signing), M13 feature grana.
- **Testovi:** —
- **Vizualno:** landing screenshot (1440×900).
- **Ograničenja:** demonstracija, ne pravni proizvod.
- **Autor mora napisati:** motivaciju, ciljeve rada, strukturu poglavlja.

## 2. Teorijska osnova

- **Činjenice:** SHA-256 content-integrity; X.509 certifikati; CMS/PKCS#7 detached potpis;
  Root CA trust anchor; razlika integritet vs. autentičnost vs. non-repudiation.
- **Commitovi:** `93c656d` (source binding), `148a0f8` (certifikat), `1183c2b` (CMS servis).
- **Testovi:** `DetachedCmsSignerTest`, `DetachedCmsVerifierTest`, `SignerCertificateOutputParserTest`.
- **Vizualno:** signing lifecycle Mermaid ([signing-lifecycle.md](signing-lifecycle.md)).
- **Ograničenja:** self-signed testni trust anchor; nije eIDAS okvir.
- **Autor mora napisati:** teoriju PKI, CMS/PKCS#7, hash funkcija, pravni kontekst
  elektroničkih potpisa (uz jasnu ogradu da implementacija nije QES).

## 3. Zahtjevi i tehnologije

- **Činjenice:** PHP 8.3, Laravel 13, PostgreSQL (auditirano 18.4), Dompdf, bacon-qr-code,
  ext-openssl, Blade + Tailwind CDN, bez Node/Vite build koraka.
- **Commitovi:** baseline + M13 README.
- **Testovi:** cijeli suite kao dokaz funkcionalnih zahtjeva.
- **Vizualno:** stack tablica iz [README](../../README.md); routes/list ispis.
- **Ograničenja:** auditirano lokalno na PostgreSQL 18.4; minimalna podržana verzija nije zasebno compatibility-testirana.
- **Autor mora napisati:** funkcionalne/nefunkcionalne zahtjeve, obrazloženje izbora stacka.

## 4. Arhitektura i baza

- **Činjenice:** slojevi (controlleri/servisi/modeli), `Contract` aggregate,
  `filled_data_snapshot` JSONB kao izvor istine, privatni storage, 30 migracija/batch 11,
  fizički CHECK/FK/partial-unique.
- **Commitovi:** `93c656d` (schema binding), migracije do `2026_07_12_000001`.
- **Testovi:** `SignatureSourceBindingSchemaTest` (12 PG metoda, fizička shema).
- **Vizualno:** [architecture.md](architecture.md) Mermaid; ERD/shema tablica;
  `migrate:status`.
- **Ograničenja:** `contract_parties`/`certificates`/`signatures` dijelom foundation; audit
  append-only na Eloquent razini.
- **Autor mora napisati:** ERD dijagram, opis tablica, obrazloženje normalizacije/JSONB
  odluke.

## 5. Implementacija

- **Činjenice:** builder/snapshot/finalizacija/final PDF/public verification; detached CMS
  potpis; owner authorization; certificate preflight; `.p7s` download; javni prikaz 8
  signala; throttling; project-local signer provisioning.
- **Commitovi:** `1f23659` (orkestracija/persistencija), `d5fe4ab` (korisnički sloj).
- **Testovi:** `ContractSigningServiceTest`, `ContractSigningHttpTest`,
  `SignerCertificatePreflightTest`, `CmsSignatureDownloadTest`, `PublicSignatureStatusTest`,
  `ProvisionLocalSignerCommandTest`.
- **Vizualno:** [document-lifecycle.md](document-lifecycle.md) i
  [signing-lifecycle.md](signing-lifecycle.md); UI screenshotovi (builder, signing stanja,
  javna provjera).
- **Ograničenja:** shared-key model; detached (ne embedded); freeze-before-sign.
- **Autor mora napisati:** opis ključnih klasa/tokova, isječke koda, obrazloženje
  sigurnosnih odluka.

## 6. Testiranje i rezultati

- **Činjenice:** default suite 683/669/3489/14/0; odvojeni combined PG opt-in run 13 passed /
  49 assertions / 0 skipped / 0 failed (12 schema proofs + 1 `PG_TEST_URL` safety
  integration); skip composition (12 PG schema + 1 PG URL-safety + 1 Windows symlink);
  scoped Pint/php -l/diff-check clean. PostgreSQL run se ne zbraja s default suiteom.
- **Commitovi:** M13 feature grana.
- **Testovi:** cijeli `tests/` + izolirana `pgsql_test` procedura.
- **Vizualno:** [test-results.md](test-results.md); terminal ispisi (sanitizirani).
- **Ograničenja:** SQLite nije dokaz PG constrainta; paralelni PG race test P3 (odgođen).
- **Autor mora napisati:** metodologiju testiranja, tablicu rezultata, analizu pokrivenosti.

## 7. Zaključak i budući rad

- **Činjenice:** lokalni signing workflow funkcionalno zaokružen (M10→M11→M12), M13
  validacija/dokumentacija.
- **Commitovi:** cijela linija.
- **Testovi:** —
- **Vizualno:** —
- **Ograničenja / budući rad (P3):** paralelni PG race test; Windows ACL; `serve => true`
  storage; per-user key (van shared-key modela); PAdES/eIDAS kao poseban, veći smjer;
  `contract_parties` runtime; revoke UI.
- **Autor mora napisati:** kritički osvrt, doprinose, ograničenja, smjerove budućeg rada
  (bez obećanja pravne valjanosti).
