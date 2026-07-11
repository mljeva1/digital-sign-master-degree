---
name: obsidian-vault-audit
description: Full read-only audit of the Obsidian vault against code, Git, routes, migrations, physical schema, and tests. Produces a discrepancy matrix and proposed (not applied) vault changes. Makes no changes anywhere.
disable-model-invocation: true
---

# Obsidian vault audit (read-only)

Use manually when the user asks for a full vault review or before a planned vault sync.

## Steps

1. Preflight: run `git branch --show-current`, `git status --short`, `git log -1 --oneline`. Confirm the Obsidian MCP is available by listing the vault root. **If the MCP is unavailable, stop immediately** — never audit from remembered vault content, and never claim the current vault was read if it was not.
2. Recursively list the entire vault (root and every subdirectory) via MCP.
3. Read **every** Markdown note via MCP before drawing conclusions.
4. Build an inventory per note: path; purpose; status; `last_verified`; empty/placeholder or filled; stale; contradictory.
5. Compare vault claims against reality: actual code, Git history/diff, `routes/web.php` (or `php artisan route:list`), migration files + `php artisan migrate:status`, physical PostgreSQL schema (read-only `information_schema`/`pg_constraint` where schema claims exist), and actual test names/results.
6. Produce a discrepancy matrix: note → claim → actual state → severity.
7. Classify every finding as exactly one of: confirmed / stale / incomplete / contradictory / unverifiable.
8. Finish with a concise proposal of targeted vault changes (which note, which section, what correction) — **proposed only, not applied**.

## Constraints

- Read-only: do not modify the vault, code, database, or Git.
- Vault access only through the Obsidian MCP.
- Do not read PII/contract table rows when checking the database; metadata catalogs only.
