# Doctrine integration notes

`CommandRecord` stores framework command identities and pending payloads. Application wiring maps this directory without introducing a dependency from AgentCore to CodingAgent.

`Infrastructure/Storage/DoctrineCommandStore` uses indexed scalar queries for identity, pending count, and FIFO payload selection. Application or rejection deletes the whole row, including its identity and payload. Pending IDs prevent duplicate enqueue; completed IDs can be submitted again. Pending rows have no TTL and survive worker restarts and cache clearing. Missing or corrupt pending payloads fail instead of producing an empty mailbox.

Configuration and migrations live at the project root:

- `config/packages/doctrine.yaml` maps entities on the default `.hatfield/state.sqlite` connection. Messenger uses a separate SQLite connection.
- `config/packages/doctrine_migrations.yaml` maps `DoctrineMigrations` to `migrations/application/`.
- Generate migrations from entity metadata with `bin/console doctrine:migrations:diff`.
- Register new migrations in `CodingAgent/Migrations/ApplicationMigrationExecutor` for runtime and PHAR startup.

The command store is a pending mailbox, not a source-delivery receipt ledger. Do not create terminal-only identity rows. Canonical transition coordination uses the existing pending journal and shared finalizer.

Test DB isolation and DAMA rollback: `tests/AGENTS.md` + testing skill.
