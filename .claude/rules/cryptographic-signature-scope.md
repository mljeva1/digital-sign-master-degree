---
paths:
  - "app/Services/Contracts/**"
  - "app/Models/{Contract,Signature,Certificate,StoredFile}.php"
  - "app/Http/Controllers/{ContractController,PublicContractVerificationController}.php"
  - "database/migrations/**/*.php"
  - "resources/views/contracts/index.blade.php"
  - "resources/views/contracts/pdf/final.blade.php"
  - "resources/views/public/contracts/verify/show.blade.php"
  - "routes/web.php"
  - "tests/**/*.php"
---

# Local CMS/X.509 Signature Scope

## Academic and legal boundary

This project may implement a local academic demonstration of a detached CMS/PKCS#7 signature over a frozen final PDF using a self-signed test X.509 certificate.

Do not describe the implementation as:
- eIDAS compliant or certified;
- qualified electronic signature, QES, QSCD, QTSP, or qualified certificate;
- advanced electronic signature / AdES unless every eIDAS requirement is explicitly implemented and proven;
- PAdES or an embedded PDF signature;
- legally equivalent to a handwritten signature;
- proof of legal validity, legal enforceability, or non-repudiation.

Use precise wording:
- "lokalna testna CMS/PKCS#7 demonstracija potpisa";
- "self-signed testni X.509 certifikat";
- "kriptografska provjera uspješna/neuspješna unutar lokalnog testnog trust anchor-a";
- "odvojeni potpisni artefakt".

## Signer semantics

A contract owner is an application authorization concept, not proof that the user is the seller, buyer, or another contractual party.

Do not claim that a seller, buyer, or both parties signed the contract unless a tested and explicit binding exists between:
- authenticated user;
- contract party;
- signature intent;
- certificate/key used for signing.

## Freeze-before-sign invariant

The required order is:

public verification and QR, if enabled
→ generate final PDF
→ freeze final PDF bytes
→ create detached CMS signature
→ verify signature

After a cryptographic signature exists, never allow:
- final PDF regeneration;
- QR regeneration;
- public-verification enabling if it rewrites the PDF;
- any operation that changes the signed PDF bytes.

Read-only PDF display, local hash checks, public-verification revocation, and archive state changes may remain available only when they do not modify signed bytes.

For a detached signature:
- document_hash_before and document_hash_after represent the same SHA-256 hash of the signed PDF;
- the signature artefact has its own StoredFile hash;
- never overwrite an existing signed PDF or signature artefact.

## Private key and certificate handling

Never store, print, log, audit, commit, upload, or copy:
- private keys;
- key passphrases;
- key file paths;
- OpenSSL command strings containing key arguments;
- certificate subject DN, issuer DN, serial number, or private storage paths in audit metadata or public pages.

The private key may only remain outside the repository and database in local developer-controlled storage.

Use argument-array process execution. Never build a shell command by string concatenation.

## Execution path (standard for real implementation)

For an actual local CMS implementation, the standard and expected execution path is the PHP `ext-openssl` API — specifically `openssl_cms_sign` and `openssl_cms_verify`. The OpenSSL CLI is not used for normal signing or verification. The CLI may serve only for read-only diagnostics/preflight, or under an explicit user-approved documented exception. Shell string concatenation is prohibited.

## Verification and public output

Cryptographic verification must use the stored frozen PDF bytes and the corresponding stored signature artefact.

A successful test verification proves only that:
- the signature matches the exact tested PDF bytes;
- verification succeeded against the configured local test certificate/trust anchor.

It does not prove:
- a qualified certificate;
- a legally verified identity;
- qualified or advanced eIDAS status;
- legal validity of the contract.

Public verification may expose only a minimal status, never certificate content, DN, serial number, private paths, signature download URLs, PII, snapshots, tokens, or PDF content.

## Required evidence

Before claiming signature functionality is complete, collect actual evidence for:
- valid CMS signature verification;
- tampered PDF verification failure;
- blocked PDF/QR regeneration after signing;
- private-key and certificate-metadata redaction;
- public verification without PII or private artefact exposure;
- real PostgreSQL schema constraints for new fields;
- full tests, scoped Pint result, global Pint result, git diff --check, and browser smoke test.