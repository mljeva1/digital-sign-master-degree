---
name: close-milestone
description: Close a completed milestone or meaningful bugfix by collecting real verification evidence and updating the Obsidian vault with factual, redacted information only.
disable-model-invocation: true
---

# Close milestone

Use manually only after the user explicitly asks to close a completed milestone or meaningful bugfix.

## Steps

1. Run relevant verification commands and record their exact output:

   powershell
   php artisan test
   vendor\bin\pint --test
   git diff --check
   php artisan migrate:status

2. For user-visible lifecycle work, record the manual browser smoke-test evidence:
- which flow was tested;
- HTTP status or visible result;
- what was confirmed;
- whether the result was directly observed by Claude or reported by the user.

3. If global Pint fails:
- identify whether changed files are among failed paths;
- report the suite as failed;
- distinguish confirmed pre-existing style debt from new style failures;
- never write that Pint passed when it did not.

4. Before writing to the vault, scan proposed text for:
- secrets;
- credentials;
- session values;
- public verification tokens;
- real OIB or VIN values;
- names and addresses;
- prices;
- private storage paths;
- PDF content.

5. Use Obsidian MCP to update:
- master-degree-obsidian/02_CURRENT_STATE.md;
- a note in 10_MILESTONES/ or 13_TASKS/ where justified;
- an ADR in 09_DECISIONS/ only for a real architectural or security decision.
- Write completed facts only. Do not write speculative plans, untested claims, or decisions that have not been made.

## Constraints
- Do not run git add, git commit, git push, or alter Git history.
- Do not modify CLAUDE.md, .claude/**, .mcp.json, hooks, agents, plugins, or settings.
- Do not claim that a cryptographic digital signature exists unless it has actually been implemented and verified.