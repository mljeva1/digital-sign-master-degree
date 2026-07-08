---
name: project-context
description: Load current project state from the Obsidian vault and the working tree before starting a non-trivial task, without making any changes.
disable-model-invocation: true
---

# Project context

Use this before any non-trivial analysis, bug fix, refactor, migration, feature, or security-sensitive change.

## Steps

1. Use the Obsidian MCP server (not direct filesystem reads) to read `00_START_HERE.md` and `02_CURRENT_STATE.md`.
2. Follow the links relevant to the task and read those notes too, via Obsidian MCP.
3. Inspect the relevant source code, routes, migrations, models, and tests for the area of the task.
4. Run `git status` and `git diff --stat` to see the current working-tree state.
5. Produce a short summary covering:
   * current state relevant to the task;
   * active blocker(s), if any;
   * scope of what the task would touch;
   * risks;
   * a proposed plan.

## Constraints

* Do not modify code, migrations, database data, or vault files.
* Treat the vault as a snapshot with a timestamp (`last_verified` in each note's frontmatter), not a live view — if the code or schema disagrees with the vault, say so explicitly rather than trusting the vault.
