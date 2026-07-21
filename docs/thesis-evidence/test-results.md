# Test results — auditirani rezultati

Svi brojevi su stvarno opaženi na feature grani `feature/m13-final-validation-thesis-readiness`
(polazište `d5fe4ab`). Zasebni PostgreSQL run se **ne** zbraja s default full-suite brojem.

## Runtime (read-only)

| Stavka | Vrijednost |
|---|---|
| PHP | 8.3.12 (ZTS, Visual C++ 2019 x64) |
| Laravel | 13.16.1 |
| Runtime DB driver | `pgsql` |
| PostgreSQL server | 18.4 |
| Razvojna baza | `digital_sign_master_degree` |
| Migracije | 30/30 Ran, batch 11 zadnji |
| Rute | 39 |

## Default suite (SQLite `:memory:`)

```
683 tests / 669 passed / 3489 assertions / 14 skipped / 0 failed
```

- Baseline prije M13 (na `d5fe4ab`): 649 / 636 / 3396 / 13 / 0.
- Prirast su M13 copy regresije te sigurnosni testovi PostgreSQL preflight
  commanda/guarda i `PG_TEST_URL` safety integrationa.

### Skip composition (14)

- **12** — PostgreSQL opt-in `SignatureSourceBindingSchemaTest` (izolirana PG test baza je
  opt-in; skipano u default SQLite runu).
- **1** — `PG_TEST_URL` safety integration (`PrepareTestPostgresUrlOverrideIntegrationTest`),
  isti PostgreSQL opt-in uvjet.
- **1** — Windows directory-symlink test (`symlink()` neprivilegiran; junction ekvivalent
  prolazi).

## PostgreSQL opt-in suite (odvojeno, izolirana `pgsql_test`)

```
PostgreSQL schema proofs:
12 passed / 39 assertions / 0 skipped / 0 failed

PG_TEST_URL safety integration:
1 passed / 10 assertions / 0 skipped / 0 failed

Combined opt-in PostgreSQL run:
13 passed / 49 assertions / 0 skipped / 0 failed
```

Ovi rezultati se **ne zbrajaju** s default suiteom.

Dokazano fizičko PostgreSQL ponašanje: FK insert violation (23503), `ON DELETE RESTRICT`
(23001), completed/pending CHECK (23514), NOT NULL (23502), partial unique (23505),
constraint/index definicije.

**Dev-baza dokaz (točna formulacija).** Tijekom PostgreSQL testnog izvršenja opaženi zaštićeni
row countovi i provjereni Signature/certificate/file bindingi u razvojnoj bazi ostali su
nepromijenjeni — read-only brojanje redaka prije/poslije identično: contracts=6, signatures=1,
certificates=4, files=20, audit_events=125, users=3. **Ova provjera nije fizički byte-level
dokaz identičnosti cijele baze.**

**Vremensko razdvajanje događaja.** Naknadni (odvojeni) guest pregled javne verifikacijske
stranice — izveden **poslije** PostgreSQL schema runa, kao dio guest browser QA — dodao je
**jedan** normalan append-only audit događaj `contract.public_verification_viewed`
(audit_events 125 → 126). Njegova metadata sadrži samo dopuštene ključeve (`public`,
`viewed_at`, `route_name`) — bez tokena, PII-ja ili patha. Zato se **ne** tvrdi da je razvojna
baza ostala potpuno neizmijenjena nakon cijelog M13 browser QA-a; tvrdi se samo da PostgreSQL
schema run nije promijenio zaštićene opažene row countove.

> Napomena: `ON DELETE RESTRICT` na PostgreSQL-u diže `restrict_violation` (23001), ne
> `foreign_key_violation` (23503). Ovo je usklađeno u testu pri prvom stvarnom izvršenju
> protiv izolirane PG baze (M13); prije toga suite nikad nije stvarno pokrenut nad PG-om.

## Ciljane signing suite

- `tests/Feature/Signing` — prolazi (native OpenSSL stvaran).
- `tests/Unit/Signing` — prolazi.

## Quality gates

- Scoped Pint nad izmijenjenim datotekama: passed.
- `php -l` nad izmijenjenim PHP datotekama: clean.
- `git diff --check`: clean.
- `php artisan migrate:status`: 30/30 Ran, batch 11.

## Granice dokaza

SQLite in-memory nije dokaz PostgreSQL CHECK/FK/JSONB/partial-unique/concurrency ponašanja.
Stvarni paralelni PostgreSQL race test nije izveden (P3, vidi
[../testing.md](../testing.md)). **M13 guest browser QA nije kreirao izolirani QA dataset** —
za read-only javnu provjeru korišten je postojeći potpisani ugovor #6 (vidi
[screenshot-index.md](screenshot-index.md)). **Autentificirani M13 browser acceptance nije
izveden u Claude sesiji** (lozinke se ne traže niti unose). M13 je zatvoren bez tog ciklusa:
status je **NOT EXECUTED / DEFERRED**, što je odluka o opsegu, a ne dokaz da je acceptance
izveden. M12 browser dokaz je povijesni i ne predstavlja novi M13 acceptance.
