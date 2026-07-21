# Thesis evidence — indeks

Ulazna točka za dokaznu građu diplomskog rada. Tekstualni artefakti su u
[thesis-evidence/](thesis-evidence/); ovaj dokument ih indeksira i navodi pravila
sanitizacije.

## Artefakti

| Datoteka | Sadržaj |
|---|---|
| [thesis-evidence/architecture.md](thesis-evidence/architecture.md) | Slojevi, aggregate, storage, Mermaid dijagram komponenti |
| [thesis-evidence/document-lifecycle.md](thesis-evidence/document-lifecycle.md) | Draft → snapshot → finalizacija → final PDF → public verification (Mermaid) |
| [thesis-evidence/signing-lifecycle.md](thesis-evidence/signing-lifecycle.md) | Detached CMS potpis i verifikacija (Mermaid sequence) |
| [thesis-evidence/test-results.md](thesis-evidence/test-results.md) | Auditirani rezultati default suitea i PG opt-in suitea |
| [thesis-evidence/screenshot-index.md](thesis-evidence/screenshot-index.md) | Indeks preporučenih screenshotova + pravila sanitizacije |
| [thesis-evidence/chapter-evidence-map.md](thesis-evidence/chapter-evidence-map.md) | Mapiranje 7 poglavlja rada na kod/commitove/testove |

## Pravila sanitizacije (obavezno prije spremanja bilo kojeg dokaza)

Nijedan tekstualni artefakt ni screenshot spremljen u repozitorij ne smije sadržavati:

- stvarna imena, e-mailove, OIB-e, adrese, telefone;
- verification tokene;
- privatne apsolutne putanje (`C:\Users\...`, `/home/...`, AppData);
- privatne ključeve, passphrase, PEM/DER sadržaj;
- lokalne credentials, lozinke, DSN.

Ako screenshot nije siguran za Git, spremi ga **izvan** repozitorija i u
[screenshot-index.md](thesis-evidence/screenshot-index.md) navedi samo opis, datum, viewport
i prikazani tok — nikad apsolutni korisnički path.

## Granica tvrdnji (za cijeli rad)

Ne iznositi: MySQL kao aktualni DB; SQLite kao runtime DB; PAdES; eIDAS/QES; osobno
posjedovanje privatnog ključa; non-repudiation; pravnu valjanost; embedded PDF signature.
Ispravno: PostgreSQL runtime, SQLite samo test harness, lokalni detached CMS/PKCS#7 sa
self-signed testnim trust anchorom, shared-key model, potpisnik = tehnički vlasnik zapisa.
