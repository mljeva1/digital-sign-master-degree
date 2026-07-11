# Obsidian vault integrity rule

1. Read and write the vault **only through the Obsidian MCP server** — never by direct filesystem access.
2. Before changing any vault note, read the full current note and its linked notes via MCP.
3. Source-of-truth priority (highest first): actual code and migrations → physical PostgreSQL schema → actual test output → Git history and diff → vault → previous chat or prompt content.
4. Update a note's `last_verified` only when its content was actually re-verified in the current session.
5. Keep current facts and historical facts clearly separated; never present a historical number (tests, batches, commits) as current.
6. Never write to the vault: secrets, tokens, private keys, passwords, `.env` content, real OIB/VIN/names/addresses or other personal data, private storage paths, or PDF content.
7. Do not overwrite `13_TASKS/ACTIVE_NEXT.md` unless the evidence shows it tracks the same workstream being updated.
8. Never mark a plan as implemented; document only verified, completed state.
9. After writing, re-read each changed note and verify its internal links.
10. If the Obsidian MCP is unavailable, stop and say so — never substitute a remembered older version of the vault as if it were current.
