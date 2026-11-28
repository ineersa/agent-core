# Doctrine integration notes

`CommandRecord` stores framework command identities and pending payloads. Application wiring maps this directory without introducing a dependency from AgentCore to CodingAgent.

`Infrastructure/Storage/DoctrineCommandStore` uses indexed scalar queries for identity, pending count, and FIFO payload selection. Application or rejection deletes the whole row, including its identity and payload. Pending IDs prevent duplicate enqueue; completed IDs can be submitted again. Pending rows have no TTL and survive worker restarts and cache clearing. Missing or corrupt pending payloads fail instead of producing an empty mailbox.

Configuration and migrations live at the project root:

- `config/packages/doctrine.yaml` maps this directory and `src/CodingAgent/Entity` on the default `.hatfield/state.sqlite` connection. Messenger queues use the session-owned sqlite-queue broker, not a Doctrine connection.
- `config/packages/doctrine_migrations.yaml` maps `DoctrineMigrations` to `migrations/application/`.
- Generate migrations from entity metadata with `bin/console doctrine:migrations:diff`.
- Register new migrations in `CodingAgent/Migrations/ApplicationMigrationExecutor` for runtime and PHAR startup.

The startup executor avoids runtime migration directory discovery in packaged
builds. Explicit Doctrine class registration has been proven in PHAR and fused
Linux x86-64 builds, but switching runners also requires a compatible version
metadata transition and a lock around planning and application. See
[Application database startup](../../../../architecture/state-and-recovery.md#application-database-startup).

The command store is a pending mailbox, not a source-delivery receipt ledger. Do not create terminal-only identity rows. Canonical transition coordination uses the existing pending journal and shared finalizer.

Test DB isolation and DAMA rollback: `tests/AGENTS.md` + testing skill (`references/helpers.md`).
