---
paths:
  - "database/migrations/**/*.php"
  - "app/Http/Controllers/**/*.php"
  - "app/Models/**/*.php"
  - "app/Services/**/*.php"
  - "tests/**/*.php"
---

# Database migrations rule

- For any suspected schema mismatch, manually invoke `/safe-migration-diagnosis` before proposing a repair migration.
- Inspect `php artisan migrate:status` before diagnosing a migration issue.
- Verify physical PostgreSQL columns and constraints through `information_schema.columns`, `pg_constraint`, or another direct read-only schema inspection. A migration status of `Ran` alone is not proof that the physical schema is correct.
- Do not read raw `.env` contents. Obtain only non-secret runtime configuration values through a narrowly scoped read-only Laravel command after permission approval.
- Compare the responsible migration files, the migrations table, the physical schema, the controller/model/service code, and the current Git diff.
- Several feature test suites (e.g. `ContractSnapshotTest`, `VehicleCatalogSearchTest`, `BuilderVehicleCatalogUiTest`, `UserContractProfileTest`) hand-build simplified schemas in `setUp()` on in-memory SQLite. They may validate application behavior, but they do not prove real migrations or PostgreSQL CHECK constraints — identify every such suite in the affected area rather than assuming a single file.
- When implementing a claim about real PostgreSQL DDL or constraints, add or update a migration-schema smoke test that uses actual migrations where feasible.
- Use forward-only migrations with `php artisan migrate`.
- Do not create a repair migration, edit database data, or run a mutation command until the user explicitly approves the smallest safe fix.
- Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, drop commands, or bulk deletion commands without explicit approval.

For the full diagnosis procedure, read `master-degree-obsidian/11_RUNBOOKS/database-and-migrations.md` through Obsidian MCP.