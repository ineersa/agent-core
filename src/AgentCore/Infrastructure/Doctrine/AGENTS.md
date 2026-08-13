# Doctrine integration notes

This directory currently holds **docs only** (no PHP entities here). App Doctrine config and migrations live at the project root:

- Connections / entities: `config/packages/doctrine.yaml` — default `state.sqlite` and `messenger_transport` SQLite under `.hatfield/` (split so app locks do not block Messenger redelivery)
- Migrations bundle: `config/packages/doctrine_migrations.yaml` — namespace `DoctrineMigrations`, path `%kernel.project_dir%/migrations`
- Migration classes: project-root `migrations/Version*.php` (generate via `bin/console doctrine:migrations:diff` from metadata; do not hand-write casually)
- Startup migration wiring: `Ineersa\CodingAgent\Migrations\*` services in `config/services.yaml`

Test DB isolation and DAMA rollback: `tests/AGENTS.md` + testing skill.
