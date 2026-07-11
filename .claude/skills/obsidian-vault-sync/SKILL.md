---
name: obsidian-vault-sync
description: Controlled update of Obsidian vault notes to match verified reality, with an audit-first workflow, minimal patches, and a written plan before any write. Only when the user explicitly requests a vault update.
disable-model-invocation: true
---

# Obsidian vault sync (controlled writes)

Use manually **only when the user explicitly asks to update the vault**. A request to review or audit is not authorization to write.

## Steps

1. First perform the relevant read-only audit steps (see `/obsidian-vault-audit`): preflight, MCP availability, read every note you intend to touch **and its linked notes**, in their current state. If the MCP is unavailable, stop.
2. Collect real evidence before writing anything: Git HEAD + branch + status; actual test output; actual routes; migration status; physical schema (read-only) where a schema claim changes.
3. Present a plan first: the exact list of notes to be changed and what changes in each. Then apply it.
4. Make minimal patches (targeted section edits), not wholesale rewrites. Preserve content that is clearly marked as historical.
5. Update `last_verified` only in notes whose content was actually re-verified now.
6. Do not write secrets, tokens, keys, passwords, `.env` content, real OIB/VIN/names/addresses, private storage paths, or PDF content.
7. Do not change `13_TASKS/ACTIVE_NEXT.md` without evidence it tracks the same workstream being updated.
8. After each change, re-read the changed note; verify internal links, commit hashes, test numbers, and migration batch numbers against the evidence from step 2.
9. Report the full list of notes read, created, and changed, with a per-note summary.

## Constraints

- Vault access only through the Obsidian MCP. Do not modify code, database, or Git.
- Every actual write operation remains subject to the local permission model — do not assume blanket write approval, and do not ask for the Obsidian write tools to be allow-listed globally.
- Never mark planned work as implemented.
