---
name: review-current-change
description: Read-only review of the actual current diff (branch vs merge-base) covering architecture, authorization, PII/audit, migrations, tests, and unintended changes, ending in an accept/conditional/reject decision. Never fixes code during the review.
disable-model-invocation: true
---

# Review current change (read-only)

Use manually before accepting any diff — including a diff produced by an AI tool. A previous report about the change is **not** evidence; only the actual diff and runtime checks are.

## Steps

1. Establish state: current branch, HEAD, upstream (if any), `git status --short`.
2. Find the appropriate merge-base (usually `git merge-base main HEAD`, or `origin/main` when reviewing pre-push) and take the real diff against it.
3. Review the **actual diff**, hunk by hunk — never only a summary or prior report of it.
4. Read the task-relevant vault context via Obsidian MCP (at minimum `02_CURRENT_STATE.md` and notes linked to the touched area).
5. Check scope: every changed file must be explainable by the approved task; flag anything outside it.
6. Check, where the diff touches them:
   - architecture (fits existing patterns, no new unapproved layers);
   - authorization (owner checks, no ID from request payload where the authenticated user is the boundary);
   - PII and audit (no sensitive values in audit metadata, logs, or public views);
   - migrations and PostgreSQL compatibility (raw statements, CHECK mirroring, forward-only);
   - mass assignment (`$fillable`/`$guarded` boundaries);
   - validation vs DB constraint alignment (constraint violations must not become 500s);
   - tests and test realism (what the SQLite hand-built schemas do and do not prove);
   - UI/browser risks (markup-only assertions vs real browser behavior);
   - unintended changes (formatting churn, unrelated files, lockfiles).
7. Separate findings into: confirmed problem / risk / unverified assumption. Do not present an assumption as a finding.
8. Return a decision: **accepted**, **conditionally accepted** (list the exact conditions), or **rejected** (list the blocking findings).
9. Name exactly one safest next step.

## Constraints

- Read-only: do not fix code, do not commit, do not push, do not modify the vault.
- Do not claim a runtime behavior works unless a command output in this session shows it.
