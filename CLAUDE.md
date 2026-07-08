# Digital Sign Master Degree — Project Instructions

## Project identity

This repository contains a Laravel 13 / PHP 8.3 / PostgreSQL diploma project:

**Aplikacija za digitalno potpisivanje dokumenata**

The primary document workflow is a vehicle sale contract.

## Source of truth

Operational truth, in priority order:

1. Current code and physical PostgreSQL schema.
2. Current Git working tree and migration status.
3. Obsidian vault notes, treated as timestamped snapshots.

If the vault conflicts with code or schema, report the mismatch explicitly and propose the factual documentation update. Do not silently trust stale documentation.

## Mandatory workflow before non-trivial work

For any non-trivial analysis, bug fix, refactor, migration, feature, security-sensitive change, or commit-scope review:

1. Manually invoke `/project-context`.
2. Read `master-degree-obsidian/00_START_HERE.md` and `master-degree-obsidian/02_CURRENT_STATE.md` through the Obsidian MCP server.
3. Read task-relevant linked vault notes.
4. Inspect relevant code, routes, migrations, models, tests, and current Git diff.
5. State:
   - files and evidence inspected;
   - current behavior;
   - blockers or mismatches;
   - proposed plan;
   - risks;
   - validation commands.

Do not mutate code, migrations, database data, vault files, Git state, `CLAUDE.md`, `.claude/**`, or `.mcp.json` before a current-turn user message explicitly authorizes that mutation.

A request to inspect, diagnose, review, plan, or explain is read-only authorization only.

## Project rules

- Use Laravel 13 conventions and PHP 8.3.
- Database is PostgreSQL.
- Do not introduce Node, Vite, npm, or a frontend build requirement.
- Keep contract PDFs in private storage.
- Never expose a private contract PDF through a public route.
- Never expose OIB, names, addresses, VIN, price, full snapshots, PDF content, credentials, tokens, session data, or private storage paths in audit metadata.
- Do not claim that a document is digitally signed unless cryptographic signing is explicitly implemented and verified.
- Do not claim OpenSSL, PAdES, eIDAS, qualified signatures, legal validity, or compliance unless explicitly implemented and verified.
- Finalized contracts are immutable.
- Do not implement an unlock path without an explicitly approved versioning design.
- Never run destructive database commands without explicit approval:
  - `php artisan migrate:fresh`
  - `php artisan migrate:refresh`
  - `php artisan migrate:reset`
  - `php artisan migrate:rollback`
  - `php artisan db:wipe`
  - database drop commands
  - bulk deletion commands

## Testing and verification

After relevant code changes, run and report exact results for:

powershell
php artisan test
vendor\bin\pint --test
git diff --check
php artisan migrate:status

- For a user-visible lifecycle change, also perform or request a manual browser smoke test.
- Do not claim a command passed unless its actual output was observed.
- If global Pint fails only on pre-existing untouched files, report that precisely. Do not say that Pint passed.
- Do not claim that a browser, Network, PDF, public verification, or local hash test was executed by Claude when it was only reported by the user.

## Superpowers integration

The local Superpowers plugin is a methodology aid, not project authority.

Project instructions, .claude/rules/, and manually invoked project skills take precedence over Superpowers skills.

Use Superpowers only where suitable:
- brainstorming for approved feature/design exploration;
- writing-plans after design approval;
- systematic-debugging for reproducible runtime bugs;
- test-driven-development for new behavior;
- verification-before-completion before claiming a fix is complete;
- requesting-code-review for a read-only quality review.
Do not use or invoke workflows that create Git worktrees, automatically spawn subagents, stage files, commit, push, merge, or modify project governance unless the user explicitly requests that exact action in the current turn.

Before using a Superpowers planning or debugging skill for non-trivial work, invoke /project-context first.

## Documentation and Git

A milestone is not closed until verification evidence has been collected.
When the user explicitly requests milestone closure, manually invoke /close-milestone.
Do not run git add, git commit, git push, branch creation, reset, clean, restore, or worktree commands without explicit current-turn authorization.
Do not write real personal data, OIB values, VIN values, public verification tokens, session values, credentials, private paths, or PDF contents to the Obsidian vault.

## Claude Code configuration governance

Claude may propose new rules, skills, agents, hooks, settings, MCP servers, or plugins only when the existing setup is demonstrably insufficient.
Claude must not create, edit, delete, or change CLAUDE.md, .claude/**, .mcp.json, hooks, permission settings, agent definitions, or plugin configuration without explicit current-turn confirmation.
New skills must not declare allowed-tools without explicit approval.
Local settings, credentials, tokens, permission grants, session data, auto-memory data, and secrets must not be committed or copied to the Obsidian vault.