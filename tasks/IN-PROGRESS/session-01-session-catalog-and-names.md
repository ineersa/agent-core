# SESSION-01 Session catalog, display names, and listing API

## Goal
Add first-class session catalog metadata so TUI session commands can show and manipulate sessions by `session id + session name`.

## Context
Scouts found `hatfield_session` currently has metadata (`cwd`, `prompt`, parent/root/model/reasoning, timestamps) but no `name`/`title` column and no `HatfieldSessionStore::listSessions()` API. Existing session IDs are DB auto-increment integers exposed as strings; `session_id === run_id` remains invariant.

This is a prerequisite for `/resume`, `/rename`, and session picker/completion UI.

## Current code facts

### Entity: `src/CodingAgent/Entity/HatfieldSession.php`
- Fields: `id` (auto-increment, cast to string), `cwd`, `prompt`, `parentId`, `rootId`, `model`, `modelProvider`, `modelName`, `reasoning`, `createdAt`, `updatedAt`
- **No `name`/`title`/`alias` column** — this is the primary gap.
- Uses `TimestampableLifecycleTrait` to auto-set `createdAt`/`updatedAt`.
- `fetchEntityOrNull()` in `HatfieldSessionStore` rejects non-numeric IDs (returns null for hex IDs).

### Migration: `migrations/Version20260601152619.php`
- SQL: `CREATE TABLE hatfield_session (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, cwd VARCHAR(255) NOT NULL, prompt VARCHAR(255) DEFAULT NULL, parent_id VARCHAR(255) DEFAULT NULL, root_id VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, model_provider VARCHAR(255) DEFAULT NULL, model_name VARCHAR(255) DEFAULT NULL, reasoning VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)`
- **New migration needed** to add `name VARCHAR(255) DEFAULT NULL`.

### Store: `src/CodingAgent/Session/HatfieldSessionStore.php`
- API: `createSession(string $prompt = ''): string`, `loadMetadata(string $sessionId): ?array`, `updateMetadata(string $sessionId, array $meta): void`, `appendTranscriptEntry(string $sessionId, TranscriptEntry $entry): void`, `getTranscript(string $sessionId): array`, `exists(string $sessionId): bool`, `resolveSessionsBasePath(): string`
- **No `listSessions()` method** — this is the primary implementation gap.
- `updateMetadata()` currently handles keys: `prompt`, `model`, `model_provider`, `model_name`, `reasoning`, `parent_id`, `root_id`, `cwd`. Must add `name`.
- `loadMetadata()` returns array with keys matching the DB columns. Must include `name`.

### Repository: `src/CodingAgent/Entity/HatfieldSessionRepository.php`
- Standard Doctrine repository, no custom methods beyond inherited `find`/`findBy`/`findAll`.
- Add a `findAllForList()` or `findRecentSorted()` query that returns id + display-relevant fields ordered by `updated_at DESC`.

## Implementation seams

### DB migration (new file in `migrations/`)
```sql
ALTER TABLE hatfield_session ADD COLUMN name VARCHAR(255) DEFAULT NULL
```

### Entity property (`src/CodingAgent/Entity/HatfieldSession.php`)
```php
#[ORM\Column(type: 'string', length: 255, nullable: true)]
public ?string $name = null;
```

### Store listing method (`src/CodingAgent/Session/HatfieldSessionStore.php`)
```php
/**
 * Return recent sessions with metadata suitable for picker display.
 * @param string $sortBy 'updated_at' (default) or 'created_at' or 'prompt'
 * @param int $limit Max results (default 50)
 * @param string $order 'DESC' (default) or 'ASC'
 * @return list<array{sessionId: string, name: ?string, displayTitle: string, prompt: ?string, model: ?string, createdAt: string, updatedAt: string}>
 */
public function listSessions(string $sortBy = 'updated_at', int $limit = 50, string $order = 'DESC'): array
```

### Display fallback logic
- If `name` is not null: display `name`
- Else if `prompt` is not null: truncate prompt to ~60 chars + `...`
- Else: `Session <id>`
- This logic should live in the store or a small helper DTO, not duplicated in every picker.

## Related tests
- `tests/CodingAgent/Session/HatfieldSessionStoreTest.php` — add methods for `listSessions()`, `name` field in `loadMetadata()`/`updateMetadata()`, display fallback
- `tests/CodingAgent/Config/SessionAwareModelResolverTest.php` — ensure `name` in metadata does not break model resolution

## Related docs
- `docs/session-storage.md` — add session name and listing API
- `docs/settings.md` — no change expected unless new config key added

## Known pitfalls
- `fetchEntityOrNull()` uses `(int)$sessionId === 0` guard; pre-DB hex IDs cannot be fetched. This is existing behavior; do not add compatibility shims.
- `updateMetadata()` silently ignores unknown keys. Adding `name` requires explicit handling or expanding the known-keys list.
- Prompt content may be long (255+ chars). Truncation for display must not corrupt multi-byte characters; use `mb_strimwidth()`.
- Session listing must exclude orphan/incomplete sessions if any exist in the DB.
- DB-touching tests must boot Symfony kernel and use test container per project rules.

## Scope
- Add nullable session display name metadata.
- Add DB-backed listing/query APIs for recent sessions.
- Provide a stable display DTO/array shape with id, name/display title, cwd, prompt preview, model/reasoning, created/updated timestamps.
- Update docs/tests.

## Out of scope
- Implementing slash commands.
- Switching active TUI sessions.
- Tree/branch navigation.
- Moving state/events storage.

## Acceptance criteria
- `hatfield_session` schema/entity supports an optional user-visible session name.
- `HatfieldSessionStore::loadMetadata()` and `updateMetadata()` include/update the session name without silently accepting unknown metadata keys beyond the documented shape.
- A DB-backed `listSessions()` style API returns recent sessions sorted by `updated_at` (or explicit requested sort) and includes `id + display name` data suitable for TUI pickers.
- Unnamed sessions have a deterministic display fallback (for example prompt preview or `Session <id>`) without mutating the DB name field.
- Tests cover create, load, update/rename metadata, listing order, and display fallback behavior.
- `docs/session-storage.md` documents session names and listing metadata.
- Validation uses Castor per project rules.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/session-01-session-catalog-and-names
Worktree: /home/ineersa/projects/agent-core-worktrees/session-01-session-catalog-and-names
Fork run:
PR URL:
PR Status:
Started: 2026-06-08T16:11:14.248Z
Completed:

## Work log
- Created: 2026-06-07T20:45:08.344Z

## Task workflow update - 2026-06-08T16:11:14.248Z
- Moved TODO → IN-PROGRESS.
- Created branch task/session-01-session-catalog-and-names.
- Created worktree /home/ineersa/projects/agent-core-worktrees/session-01-session-catalog-and-names.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/session-01-session-catalog-and-names.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/session-01-session-catalog-and-names.

## Task workflow update - 2026-06-08T16:18:02.842Z
- Validation: Scouts inspected session entity/repository/store, migration executor, DB test patterns, and docs/downstream session task prerequisites.
- Summary: Start-phase scouts completed. Current implementation seams: add nullable `name` field to `HatfieldSession`, new migration adding `hatfield_session.name`, add migration FQCN to `ApplicationMigrationExecutor::KNOWN_MIGRATIONS`, add repository query method for recent/listed sessions, extend `HatfieldSessionStore::loadMetadata()`/`updateMetadata()` and add `listSessions()` with deterministic computed display title fallback. Tests should primarily extend `HatfieldSessionStoreTest` using `IsolatedKernelTestCase`, and add/adjust `SessionAwareModelResolverTest` coverage so extra `name` metadata does not affect model resolution. Docs update target is `docs/session-storage.md` metadata shape, naming/display subsection, and open gaps table.
- task-start: launched 3 scouts for code/API seams, tests/DB patterns, and docs/downstream picker requirements. Preparing implementation fork.
