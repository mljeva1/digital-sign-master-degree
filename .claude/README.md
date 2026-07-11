# Claude Code project configuration

Index of this repository's Claude Code setup. Operational detail lives in the files themselves — this is only the map.

## Layers

- **`CLAUDE.md` (repo root)** — always-loaded project instructions: source-of-truth order, mandatory workflow, security boundaries, governance.
- **`.claude/rules/`** — path-scoped or global rules, auto-applied when matching files are touched.
- **`.claude/skills/`** — manually invoked workflows (`/skill-name`). None may auto-trigger destructive or write behavior.
- **`.claude/settings.local.json`** — personal local settings and permissions. **Gitignored (`.gitignore:30`), never committed.** Contains no secrets, but stays local.
- **Plugins** (Superpowers, ui-ux-pro-max, …) — external methodology aids. **Never project authority**; project instructions, rules, and project skills take precedence. The external UI/UX plugin is advisory only, not a source of truth.

## Rules

| Rule | Purpose |
|---|---|
| `contract-finalization-security.md` | Finalized-contract immutability, private PDF boundary, public-verification privacy, audit metadata limits |
| `cryptographic-signature-scope.md` | Local academic CMS/X.509 scope; no eIDAS/PAdES/legal-validity claims; key/DN secrecy; freeze-before-sign |
| `database-migrations.md` | Read-only schema diagnosis first, physical PostgreSQL verification, forward-only migrations, no destructive commands |
| `obsidian-vault-integrity.md` | Vault access only via MCP, source-of-truth order, `last_verified` discipline, no secrets/PII in vault |
| `user-contract-profile-pii.md` | M7 profile PII boundaries: owner-only access, optional fields, no PII in audit metadata |
| `ui-ux-project.md` | UI stack constraints (Blade + Tailwind CDN, no build step), design direction, accessibility/responsive minimum |

## Skills (manual invocation)

| Zadatak | Skill |
|---|---|
| Učitavanje projektnog konteksta | `/project-context` |
| Read-only migracijska dijagnostika | `/safe-migration-diagnosis` |
| Review trenutnog diffa | `/review-current-change` |
| Potpuni read-only vault audit | `/obsidian-vault-audit` |
| Kontrolirani vault sync | `/obsidian-vault-sync` |
| UI/UX discovery bez implementacije | `/ui-ux-project-audit` |
| Zatvaranje potvrđenog milestonea | `/close-milestone` |

When to invoke: `/project-context` before any non-trivial task; `/review-current-change` before accepting a diff; `/obsidian-vault-audit` (read-only) before `/obsidian-vault-sync` (writes, each write user-approved); `/ui-ux-project-audit` before any redesign work; `/close-milestone` only when the user explicitly asks to close a verified milestone.

## Vault access

The Obsidian vault (`master-degree-obsidian/`) is read and written **only through the Obsidian MCP server**. Direct filesystem reads of the vault are denied by local permissions. Vault **audit** (read-only) and vault **sync** (writes) are separate operations with separate skills.
