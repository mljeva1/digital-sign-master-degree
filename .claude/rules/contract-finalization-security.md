---
paths:
  - "app/Http/Controllers/ContractController.php"
  - "app/Http/Controllers/PublicContractVerificationController.php"
  - "app/Http/Requests/**/*.php"
  - "app/Http/Middleware/**/*.php"
  - "app/Models/Contract.php"
  - "app/Models/AuditEvent.php"
  - "app/Policies/**/*.php"
  - "app/Services/**/*.php"
  - "database/migrations/**/*.php"
  - "resources/views/contracts/**/*.blade.php"
  - "resources/views/public/**/*.blade.php"
  - "routes/web.php"
  - "tests/**/*.php"
---

# Contract finalization and security rule

- A finalized contract is immutable. Do not add an unlock path without an explicitly approved versioning design.
- The immutable object is the finalized snapshot and contract content. Do not claim that generated PDF bytes are immutable if the current implementation may regenerate the PDF artifact after finalization.
- Keep final PDFs in private storage. A public verification page must never expose PDF content, a private storage path, a download URL, or contract snapshot fields.
- Public verification may expose only the intended status, finalization time, and official hash values.
- Invalid, disabled, revoked, expired, or mismatched verification tokens must not reveal whether a contract exists. Preserve the current non-enumerating response behavior.
- Treat a public verification token as a bearer-style lookup URL. It may appear in the public verification URL and QR code by design, but must never be written to audit metadata, logs, fixtures with real data, the Obsidian vault, or screenshots shared outside local testing.
- Audit metadata must never expose OIB, names, addresses, VIN, price, snapshot fields, PDF content, credentials, session data, tokens, or private storage paths.
- Do not claim OpenSSL, PAdES, eIDAS, qualified signatures, legal validity, or cryptographic digital signing unless truly implemented and verified.
- When altering finalization, PDF generation, QR generation, verification, authorization, audit logging, or related migrations, identify the security boundary and provide a concrete verification plan.

For the full security and audit boundary, read `master-degree-obsidian/07_SECURITY_AND_AUDIT.md` through Obsidian MCP.