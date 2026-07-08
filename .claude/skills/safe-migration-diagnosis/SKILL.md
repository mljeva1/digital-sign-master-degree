---
name: safe-migration-diagnosis
description: Diagnose a suspected PostgreSQL schema or migration mismatch through read-only checks, then report the smallest safe fix and wait for explicit confirmation.
disable-model-invocation: true
---

# Safe migration diagnosis

Use manually whenever a task involves a suspected missing column, migration error, schema mismatch, or an unverified migration claim.

## Steps

1. Read through Obsidian MCP:
   - `master-degree-obsidian/11_RUNBOOKS/database-and-migrations.md`
   - `master-degree-obsidian/02_CURRENT_STATE.md`
   - relevant linked schema and lifecycle notes.

2. Confirm only non-secret runtime facts:
   - Laravel environment;
   - active database connection;
   - active database name.

   Do not open or display raw `.env` content. Use a narrowly scoped read-only Laravel command only after permission approval.

3. Run:

   powershell
   php artisan about
   php artisan migrate:status
   git status --short
   git diff --stat

4. Inspect the physical PostgreSQL schema directly:
- relevant information_schema.columns;
- relevant pg_constraint definitions;
- when needed, indexes and foreign keys;
- active database name.

5. Compare:
- migration file(s);
- migrations table;
 -physical schema;
- current controller/model/service code;
- current Git diff.

6. Inspect tests and identify whether they:
- use actual Laravel migrations;
- hand-build a simplified schema;
- prove PostgreSQL constraints;
- prove only application behavior.

7. Report:
- confirmed facts;
- unverified facts;
- exact source of any mismatch;
- smallest non-destructive fix;
- whether a repair migration is actually needed;
- whether a migration-schema smoke test is needed.

## Constraints
- Never run destructive migration or database commands.
- Never modify code, migrations, database data, vault files, or Git state.
- Never claim a missing-column problem is solved until the relevant runtime path has been retested.
- End by asking for explicit confirmation before creating or modifying any migration, code, database data, or test.