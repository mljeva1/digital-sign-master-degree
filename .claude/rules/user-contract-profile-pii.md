---
paths:
  - "app/Models/UserContractProfile.php"
  - "app/Models/User.php"
  - "app/Http/Controllers/UserContractProfileController.php"
  - "app/Http/Requests/**/*Profile*.php"
  - "app/Services/Audit/AuditLogger.php"
  - "database/factories/UserContractProfileFactory.php"
  - "resources/views/profile/**/*.blade.php"
  - "routes/web.php"
  - "tests/**/*UserContractProfile*.php"
---

# User contract profile PII rule (M7)

Confirmed M7.1 decisions — do not re-litigate without an explicit new decision:

- `UserContractProfile` is a 1:1 profile of the authenticated user (individual person only; no company fields).
- Always resolve the profile from the authenticated user (`$request->user()->contractProfile()`); a profile route must never accept an arbitrary profile or user ID, and `user_id` is never accepted from request payload (it is also excluded from `$fillable`).
- All profile PII fields are optional: `first_name`, `last_name`, `oib`, `address_line1`, `address_line2`, `city`, `postal_code`, `country_code`, `phone`.
- OIB: nullable, non-unique, exactly 11 digits when present, **no checksum validation**. Never state that the OIB identifies a legally verified person.
- `country_code`: nullable, two uppercase ASCII letters when present, **no DB default** — the database never assumes a country.
- The profile must not automatically change contracts. `filled_data_snapshot` remains the contract source of truth; builder autofill belongs to M7.3 (not M7.2); `contract_parties` is not an active profile runtime.
- Profile **values** must never end up in audit metadata. If profile audit events exist, metadata may contain only structural data (e.g. operation name, names of changed fields) — never field values.
- Mirror the PostgreSQL CHECK constraints (OIB and country_code format) with application-level validation so constraint violations surface as validation errors, not 500s.
