# Doctrine integration notes

`CommandRecord` stores framework command identities and pending payloads. Application wiring maps this directory without introducing a dependency from AgentCore to CodingAgent.

`Infrastructure/Storage/DoctrineCommandStore` uses indexed scalar queries for identity, pending count, and FIFO payload selection. Applied or rejected records retain their identity and status but discard payload and checksum. No TTL or cache clear removes these records. Missing or corrupt pending payloads fail instead of producing an empty mailbox.

Configuration and migrations live at the project root:

- `config/packages/doctrine.yaml` maps entities on the default `.hatfield/state.sqlite` connection. Messenger uses a separate SQLite connection.
- `config/packages/doctrine_migrations.yaml` maps `DoctrineMigrations` to `migrations/application/`.
- Generate migrations from entity metadata with `bin/console doctrine:migrations:diff`.
- Register new migrations in `CodingAgent/Migrations/ApplicationMigrationExecutor` for runtime and PHAR startup.

The durable command store does not itself fence every canonical source delivery. That acceptance guard and durable hook recovery remain separate SPEC03 work.

Test DB isolation and DAMA rollback: `tests/AGENTS.md` + testing skill.
