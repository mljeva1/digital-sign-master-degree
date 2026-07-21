# Testing — default suite, signing suite, izolirana PostgreSQL test baza

## Default suite (brzi izolirani baseline)

Default test harness je **SQLite `:memory:`** (`phpunit.xml`: `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:`). To je namjerno brz i izoliran baseline; **nije** runtime baza i ne
dira razvojni PostgreSQL.

```bash
php artisan test
```

Auditirani baseline (M13, na feature grani):
**683 tests / 669 passed / 3489 assertions / 14 skipped / 0 failed.**

Ciljane signing suite:

```bash
php artisan test tests/Feature/Signing
php artisan test tests/Unit/Signing
```

### Skip composition (14)

- **12** — PostgreSQL opt-in metode u `SignatureSourceBindingSchemaTest` (skipane u default
  SQLite runu jer je izolirana PG test baza opt-in; vidi niže).
- **1** — `PG_TEST_URL` safety integration
  (`PrepareTestPostgresUrlOverrideIntegrationTest`), isti PostgreSQL opt-in uvjet.
- **1** — Windows directory-symlink test (`symlink()` je neprivilegiran na hostu). Windows
  junction ekvivalent **prolazi** i nije skipped.

Granice dokaza: SQLite ne dokazuje PostgreSQL regex CHECK, JSONB, sve FK/UNIQUE definicije,
partial unique indekse ni migracijske batcheve. Za takve tvrdnje koristi PG opt-in suite ili
read-only fizičku provjeru sheme.

## Izolirana PostgreSQL test baza

`SignatureSourceBindingSchemaTest` dokazuje fizičko PostgreSQL ponašanje (FK, `ON DELETE
RESTRICT`, CHECK, partial unique) koje SQLite ne može predstaviti. Pokreće se **odvojeno**,
nad **izoliranom** test bazom — nikad nad razvojnom bazom i nikad kao dio default suitea.

### Konekcije (test i development identitet)

Izolacija se dokazuje usporedbom **dvije stvarne PostgreSQL baze**, pa `config/database.php`
sadrži dvije namjenske konekcije.

**`pgsql_test`** — izolirani test target. Ključna sigurnosna odluka: naziv baze **nikad** se
ne vraća na `DB_DATABASE` — default je zaseban literal s `_test` markerom. Credentials se
smiju naslijediti od dev servera radi praktičnosti, ali baza je uvijek odvojena.

```
PG_TEST_URL=
PG_TEST_HOST=          # fallback: DB_HOST → 127.0.0.1
PG_TEST_PORT=          # fallback: DB_PORT → 5432
PG_TEST_DATABASE=      # fallback: digital_sign_master_degree_test (nikad DB_DATABASE)
PG_TEST_USERNAME=      # fallback: DB_USERNAME
PG_TEST_PASSWORD=      # fallback: DB_PASSWORD
```

**`pgsql_development`** — eksplicitni development identitet koji gate uspoređuje s targetom.
Postoji jer `phpunit.xml` postavlja `DB_DATABASE=:memory:`: tijekom test runa env-driven
`pgsql` konekcija više ne pokazuje na stvarnu razvojnu bazu, a predavanje
`config('database.default')` (SQLite `:memory:`) usporedilo bi PostgreSQL bazu sa SQLite
datotekom i **nikad** ne bi dokazalo izolaciju između dvije stvarne PostgreSQL baze. Zato
naziv baze dolazi iz `PG_DEVELOPMENT_DATABASE` s literal defaultom — **nikad** iz
`DB_DATABASE`. Konekcija se koristi isključivo read-only (`SELECT current_database()`);
nijedna migracija ni fixture write je ne cilja.

```
PG_DEVELOPMENT_URL=
PG_DEVELOPMENT_HOST=       # fallback: DB_HOST → 127.0.0.1
PG_DEVELOPMENT_PORT=       # fallback: DB_PORT → 5432
PG_DEVELOPMENT_DATABASE=   # fallback: digital_sign_master_degree (nikad DB_DATABASE)
PG_DEVELOPMENT_USERNAME=   # fallback: DB_USERNAME
PG_DEVELOPMENT_PASSWORD=   # fallback: DB_PASSWORD
```

Ne commitaj stvarne credentials; gornje su prazan primjer.

### Sigurnosni gate (fail-closed, skip-vs-fail)

Isti fail-closed skup pravila dijele `testing:prepare-postgres` naredba i opt-in gate u
`SignatureSourceBindingSchemaTest` (kroz `App\Support\Testing\PostgresTestConnectionGuard`),
pa ne mogu driftati. Gate razrješava **stvarni** `SELECT current_database()` za obje
konekcije, pa je i `PG_TEST_URL`/DSN override koji tajno cilja dev bazu uhvaćen i odbijen.

Ponašanje test gatea:

- **opt-in ISKLJUČEN** (`DB_PG_TEST_ENABLED` nije truthy) → **precizan skip** (očekivano, nije
  greška);
- **opt-in UKLJUČEN + sigurna, izolirana konfiguracija** → testovi se **izvršavaju**;
- **opt-in UKLJUČEN + nesigurna/nerazrješiva konfiguracija** → **failure, ne skip** (npr.
  `PG_TEST_URL` koji razrješava dev bazu, ista baza kao dev, bez `_test` markera, ne-pgsql
  target, te **nekonfiguriran / ne-PostgreSQL / nerazrješiv development identitet**). Time
  nesigurna opt-in konfiguracija nikad ne izgleda kao zeleni run.

Gate faila **prije** `DB::beginTransaction()` i prije ijednog fixture inserta, pa nesigurna
konfiguracija ne može ništa zapisati.

Svaki test dodatno radi unutar transakcije koja se u `tearDown` rollbacka — nijedan redak ne
ostaje čak ni u test bazi.

### Postupak

Koristi **isključivo** sigurnu wrapper naredbu za pripremu sheme. Ona radi fail-closed
preflight (oba pgsql, target ≠ dev po stvarnom `current_database()`, naziv završava na
`_test`/`_testing`) i tek tada pokreće forward-only migraciju samo nad `pgsql_test`.

Prvo jednom kreiraj izoliranu test bazu (server-level `CREATE DATABASE
digital_sign_master_degree_test` — ne dira dev retke).

Auditirano lokalno okruženje je Windows, pa je PowerShell primarni primjer:

```powershell
# 1. Sigurna priprema izolirane test sheme (fail-closed preflight + forward-only migrate).
php artisan testing:prepare-postgres

# 2. Opt-in + eksplicitni development identitet koji schema gate uspoređuje s targetom.
#    PG_DEVELOPMENT_DATABASE postavi samo ako tvoja razvojna baza nije
#    digital_sign_master_degree (default).
$env:DB_PG_TEST_ENABLED = 'true'
$env:DB_PG_TEST_CONNECTION = 'pgsql_test'
$env:PG_DEVELOPMENT_DATABASE = 'digital_sign_master_degree'

# 3. Combined opt-in PostgreSQL run.
php artisan test tests/Feature/SignatureSourceBindingSchemaTest.php tests/Feature/Testing/PrepareTestPostgresUrlOverrideIntegrationTest.php

# 4. Ukloni privremene varijable.
Remove-Item Env:DB_PG_TEST_ENABLED -ErrorAction SilentlyContinue
Remove-Item Env:DB_PG_TEST_CONNECTION -ErrorAction SilentlyContinue
Remove-Item Env:PG_DEVELOPMENT_DATABASE -ErrorAction SilentlyContinue
```

Bash alternativa (zasebno, ne miješati s PowerShell sintaksom):

```bash
php artisan testing:prepare-postgres
DB_PG_TEST_ENABLED=true DB_PG_TEST_CONNECTION=pgsql_test PG_DEVELOPMENT_DATABASE=digital_sign_master_degree \
  php artisan test tests/Feature/SignatureSourceBindingSchemaTest.php tests/Feature/Testing/PrepareTestPostgresUrlOverrideIntegrationTest.php
```

> **Ne pokreći** `php artisan migrate --database=pgsql_test --force` izravno. Direktna
> migracija zaobilazi preflight, pa mis-konfigurirani `PG_TEST_DATABASE`/`PG_TEST_URL` može
> migrirati razvojnu bazu prije nego što je test-class gate odbije. `testing:prepare-postgres`
> zatvara taj write-before-gate prozor.

Očekivano (combined opt-in run): **13 passed / 49 assertions / 0 skipped / 0 failed** (12
schema proofs + 1 `PG_TEST_URL` safety integration). Tijekom izvršenja opaženi zaštićeni row
countovi i provjereni Signature/certificate/file bindingi u razvojnoj bazi ostaju
nepromijenjeni (read-only brojanje redaka prije/poslije). **Ova provjera nije fizički
byte-level dokaz identičnosti cijele baze.** Nakon runa test baza ostaje s praznim domenskim
tablicama (samo `migrations` popunjen); može ostati ili se kontrolirano obrisati.

> Ne miješaj brojeve: zasebni PG run (12) **ne** zbraja se s default full-suite brojem.

## PostgreSQL concurrency (P3 — odgođeno)

Stvarni paralelni dvo-procesni signing race test **nije** implementiran u ovom scopeu:
Windows nema `pcntl_fork`, a deterministična dvo-procesna sinkronizacija zahtijevala bi ili
`sleep()`-barijeru (nepouzdan dokaz) ili tešku advisory-lock barijeru sklonu platform-
specific false-greenu. Fizički last-resort concurrency guard —
`signatures_contract_user_source_active_unique` partial unique indeks — **jest** stvarno
dokazan na PostgreSQL-u kroz tri metode ove suite (second-pending, completed-when-pending,
second-completed). Aplikacijski lock/idempotency pokriveni su feature testovima s
determinističkim seamovima. Paralelni PG race ostaje preporučeni (P3) dokaz i ne smije se
predstavljati postojećim lock/idempotency testovima.

## Quality gates

```bash
php artisan test
php artisan migrate:status
php artisan route:list
vendor/bin/pint --test <izmijenjene datoteke>   # scoped; globalni rezultat navedi odvojeno
git diff --check
```

Za DB/CHECK promjene: dodatna read-only fizička provjera (`pg_constraint`,
`information_schema`, `pg_get_constraintdef`, `pg_indexes`). Za UI/JS lifecycle promjene:
stvarni browser smoke.
