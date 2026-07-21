# Screenshot index

Indeks preporučenih screenshotova za diplomski rad i pravila sanitizacije. **Binarni
screenshotovi se ne commitaju automatski** — ovaj indeks opisuje što snimiti, na kojem
viewportu i koji tok prikazuje. Ako screenshot nije siguran za Git, spremi ga izvan
repozitorija i ovdje navedi samo opis + sigurnu lokacijsku kategoriju (ne apsolutni path).

## Pravila sanitizacije (obavezno)

Screenshot smije biti spremljen samo ako **nema**: stvarnih imena/e-mailova/OIB-a/adresa/
telefona; verification tokena; privatnih putanja; ključa/passphrasea; lokalnih credentials.
Koristi `m13-qa` sintetički identitet. Označi svaki screenshot datumom, viewportom i
prikazanim tokom.

## Preporučeni screenshotovi

| # | Tok | Viewport | Sanitizacijska napomena |
|---|---|---|---|
| 01 | Landing (signing sekcija) | 1440×900 | bez PII |
| 02 | Auth modal (login/register tabovi) | 1440×900 | ne popunjavati stvarne podatke |
| 03 | Dashboard | 1440×900 | sintetički QA korisnik |
| 04 | Contract builder + vehicle autocomplete | 1440×900 | sintetički podaci |
| 05 | Builder profile autofill | 768×1024 | sintetički profil |
| 06 | Finalizacija (confirm modal) | 1440×900 | „nije digitalni potpis" vidljivo |
| 07 | Final PDF (privatni prikaz) | 1440×900 | sintetički ugovor |
| 08 | Public verification (8 signala) | 1440×900 | token izrezati iz URL-a |
| 09 | Certificate preflight panel | 1440×900 | samo skraćeni otisak, bez privatnih polja |
| 10 | Signing stanja (ready → signed) | 1440×900 | — |
| 11 | `.p7s` download potvrda | 1440×900 | filename `ugovor-{id}-potpis.p7s` |
| 12 | Mobile nav „Više" dropdown | 390×844 | provjera clippinga/overflowa |
| 13 | Javna provjera responsivnost | 390×844 | token izrezati |

## Rezultati QA runa (M13, 2026-07-20)

Browser QA izveden je uživo protiv lokalnog dev servera. Napomena o okruženju: `screenshot`
funkcija browser panea visi u ovom okruženju, pa su opažanja prikupljena kroz
`read_page` (accessibility tree), `get_page_text`, izvršeni JavaScript (mjerenje overflowa)
i console logove — svako opažanje je stvarno izmjereno, ne procijenjeno. **Binarni
screenshotovi nisu spremljeni ni commitani.**

Autentikacija: prijava kao korisnik nije izvedena (sigurnosno pravilo: bez kreiranja računa
i unosa lozinki; dosljedno M12 presedanu). Zato su **guest površine verificirane uživo**, a
**autentificirani signing tok dokazan je kriptografski** kroz javnu provjeru stvarnog
potpisanog ugovora (8/8 zelenih signala) te postojećim M12 feature/browser dokazima.

| Površina | Viewport | Opaženo |
|---|---|---|
| Landing (nova signing sekcija) | 1440 / 768 / 390 | bez horizontalnog overflowa (scrollW ≤ clientW), Tailwind CDN učitan, `#potpisivanje` prisutan, „CMS/PKCS#7" + „.p7s" + „Nije PAdES" prisutni, „U pripremi"/„još nije implementirano" uklonjeni, 0 console grešaka |
| Javna provjera (ugovor #6, read-only) | 1440 / 768 / 390 | 8/8 odvojenih signala prikazano, bez horizontalnog overflowa, 0 console grešaka, bez OIB-a/PII-ja/tokena u tijelu stranice, samo dopušteni SHA-256 digesti |

- **QA dataset:** M13 guest browser QA **nije kreirao izolirani QA dataset**. Za read-only
  javnu provjeru korišten je **postojeći potpisani ugovor #6** (nije izolirani ni disposable
  QA dataset). Zbog toga je taj guest pregled dodao **jedan** normalan append-only audit
  događaj `contract.public_verification_viewed` (metadata: `{public, viewed_at, route_name}` —
  bez tokena/PII/putanje). Opaženi zaštićeni row countovi i provjereni bindingi ostali su
  nepromijenjeni, ali to **nije** tvrdnja da je cijela baza ostala potpuno neizmijenjena nakon
  browser QA-a (taj jedan audit događaj jest dodan). Nijedan zaštićeni redak (contract #6,
  Signature #19, certifikati #4/#5/#6) nije mijenjan.
- **Mobile „Više" dropdown:** ostaje dokumentirani P3 (pre-M13). Nije reproduciran uživo jer
  je u autentificiranoj contracts stranici koju QA nije prijavljivao; blind CSS izmjena bez
  žive reprodukcije nije opravdana i mogla bi uvesti regresiju.
- **`.p7s` download (MIME/filename):** owner-only autorizirana ruta; pokriveno M12
  `CmsSignatureDownloadTest` (Content-Type `application/pkcs7-signature`, filename
  `ugovor-{id}-potpis.p7s`, `X-Content-Type-Options: nosniff`, prvi bajt `0x30`).

## Statusi acceptance

```text
M13 guest landing QA:                 EXECUTED / IZVRŠENO
M13 guest public-verification QA:     EXECUTED / IZVRŠENO
M13 authenticated manual acceptance:  NOT EXECUTED / DEFERRED
Sanitized binary screenshots:         NOT CAPTURED / DEFERRED
```

M13 je zatvoren **bez** dodatnog authenticated manualnog browser acceptance ciklusa — to je
svjesna odluka o opsegu, a **ne** dokaz da je acceptance izveden. Claude se nije prijavio kao
korisnik i ne tvrdi da je authenticated M13 browser QA izvršen; kreiranje korisnika ili unos
lozinke izvodi isključivo korisnik svojim postupkom. M12 browser dokaz je **povijesni** i ne
predstavlja novi M13 acceptance. Ugovor #6 i `Signature #19` ostaju povijesni dokaz.
Guest QA nije kreirao izolirani M13 QA dataset.

## Manualni authenticated checklist (odgođeno — NIJE izvršeno)

Izvedi na **1440, 768 i 390 px**. Ništa u ovom popisu nije označeno izvršenim; to je vodič za
korisnički acceptance pass. Za signing korak koristi vlastiti lokalni testni certifikat
(`signing:provision-local-signer`). Ne snimaj URL traku s verification tokenom.

1. Profil create/update (`/profile`).
2. Builder profile autofill (Ja sam prodavatelj / kupac / ručni unos).
3. Vehicle catalog autocomplete (npr. „golf 7").
4. Documents list/upload/open (owner/purpose-scoped).
5. Certificate preflight panel (missing/active/expired/inactive/ambiguous).
6. Signing UI stanja: not-ready → ready (confirm/disabled) → signed.
7. Signing POST (owner-only, CSRF, throttle) i uspješan potpis.
8. `.p7s` download: filename `ugovor-{id}-potpis.p7s`, MIME `application/pkcs7-signature`,
   uspješan download.
9. Perzistirana javna provjera nakon reloada (idempotentno stanje).
10. Svih 8 verification signala prikazano ispravno.
11. Unauthorized/foreign-owner smoke (tuđi sign/download → 403).
12. Mobile „Više" dropdown na 390 px (clipping/overflow).
13. Horizontalni overflow na svakom viewportu.
14. Console i network greške (0 očekivano; 404/403/419/429 abnormalnosti).
15. Sanitizacija svakog screenshota prije spremanja (bez PII/tokena/patha/ključa).
