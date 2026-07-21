# Setup — čist lokalni razvoj

Ponovljiv postupak za pokretanje projekta na čistoj mašini. README je glavni ulaz; ovaj
dokument je detaljniji vodič. Svi koraci su lokalni (`local` okruženje); nema produkcijskog
deploya.

## Preduvjeti

- PHP `^8.3` s ekstenzijama: `pdo_pgsql`, `openssl`, `mbstring`, `fileinfo`, `gd`/`zlib`
  (za Dompdf), uobičajene Laravel ekstenzije.
- Composer 2.
- Lokalni PostgreSQL server. Auditirano lokalno razvojno okruženje koristi PostgreSQL 18.4.
  Minimalna podržana PostgreSQL verzija nije zasebno utvrđena compatibility testiranjem.
- Bez Node/Vite/npm — frontend se učitava kroz Tailwind Browser CDN.

## Koraci

```bash
# 1. Ovisnosti
composer install

# 2. Konfiguracija
cp .env.example .env
php artisan key:generate

# 3. Baza — kreiraj razvojnu bazu na PostgreSQL serveru
#    (naziv default: digital_sign_master_degree). Upiši u .env:
#    DB_CONNECTION=pgsql
#    DB_HOST=127.0.0.1
#    DB_PORT=5432
#    DB_DATABASE=digital_sign_master_degree
#    DB_USERNAME=<tvoj lokalni PG korisnik>
#    DB_PASSWORD=<tvoja lokalna PG lozinka>

# 4. Migracije (forward-only)
php artisan migrate

# 5. Pokretanje
php artisan serve
# → http://127.0.0.1:8000
```

## Provjera zdravlja

```bash
php -v
php artisan --version
php artisan about
php artisan migrate:status     # očekivano: 30/30 Ran, batch 11 zadnji
php artisan route:list         # očekivano: 39 ruta
```

## Storage

- Aktivni disk je `local`, root `storage/app/private`. Draft/final PDF-ovi i signer
  materijal su privatni i **ne** serviraju se javnim assetom.
- `php artisan storage:link` **nije** potreban za privatni tok (owner-checked controller
  streamovi dohvaćaju privatne datoteke).

## Sigurnosna pravila (obavezno)

- Nikad ne pokreći `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`,
  `db:wipe`, DROP/TRUNCATE ni bulk DELETE nad razvojnom bazom.
- Migracije su forward-only (`php artisan migrate`).
- Ne commitaj `.env`, privatne PDF-ove, signer ključeve/certifikate/CMS artefakte, SQLite
  katalog izvor ni lokalne credentials.

## Local signer (opcionalno, za signing tok)

Za potpisivanje treba lokalni testni signer identitet — vidi
[signing-and-verification.md](signing-and-verification.md#local-signer-provisioning).
