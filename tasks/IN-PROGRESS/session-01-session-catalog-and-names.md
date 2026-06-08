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
Fork run: s4ontgiphzx7
PR URL: https://github.com/ineersa/agent-core/pull/108
PR Status: open
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

## Task workflow update - 2026-06-08T16:18:51.112Z
- Recorded fork run: ge9ed9uc2eaa
- Summary: Implementation fork launched in task worktree `/home/ineersa/projects/agent-core-worktrees/session-01-session-catalog-and-names` with exact SESSION-01 instructions covering entity/migration/runtime migration list, repository/store listing API, metadata name handling, tests, docs, Castor validation subset, and commit expectations.
- task-start: launched implementation fork ge9ed9uc2eaa. Main agent will verify commit/diff and record validation when the fork report arrives.

## Task workflow update - 2026-06-08T16:33:18.454Z
- Recorded fork run: ge9ed9uc2eaa
- Validation: Verified commit exists at worktree HEAD: 8813499c (`SESSION-01: Add session catalog names and listing`).; Verified worktree clean on branch `task/session-01-session-catalog-and-names`.; Verified diff vs origin/main: docs/session-storage.md, migrations/Version20260608162000.php, HatfieldSession.php, HatfieldSessionRepository.php, ApplicationMigrationExecutor.php, HatfieldSessionStore.php, SessionAwareModelResolverTest.php, HatfieldSessionStoreTest.php; 8 files changed, 500 insertions, 5 deletions.; Fork validation: `castor test` PASS — 2069 tests, 6087 assertions, 0 errors/failures/skipped.; Fork validation: `castor deptrac` PASS — 0 violations, 0 errors.; Fork validation: `castor phpstan` PASS — 0 errors, 0 file errors.; Fork validation: `castor cs-fix` auto-fixed 2 files; `castor cs-check` PASS — clean, 0 files fixed.
- Summary: Implementation fork completed SESSION-01 at commit `8813499c` (`SESSION-01: Add session catalog names and listing`). Verified worktree clean on branch `task/session-01-session-catalog-and-names`; diff vs origin/main is 8 files changed (+500/-5). Implemented nullable `HatfieldSession::$name`, new migration `Version20260608162000`, runtime migration registration, safe repository `findForCatalog()` query, `HatfieldSessionStore::listSessions()` catalog API with computed `displayTitle`/`promptPreview`, `loadMetadata()`/`updateMetadata()` name handling, session store/model resolver tests, and `docs/session-storage.md` updates. No TUI commands/session switching/tree behavior added, per scope.
- task-start: implementation fork ge9ed9uc2eaa completed and committed 8813499c. Per task-start workflow, stopping before reviewer/PR/full gate; next step is task-to-pr when the user is ready.

## Task workflow update - 2026-06-08T16:43:34.717Z
- Validation: Reviewer verdict: APPROVE WITH SUGGESTIONS for HEAD 8813499c.; Post-fetch branch diff verified clean: 8 expected SESSION-01 files only; no unrelated task files in `git diff --name-status origin/main...HEAD`.
- Summary: Reviewer subagent reviewed HEAD `8813499c` and returned APPROVE WITH SUGGESTIONS. Actionable findings to address before PR: (1) `HatfieldSessionStore::listSessions()` PHPDoc promises always-present nullable model/reasoning keys, while implementation conditionally omits them; (2) add whitespace-only name-clearing test; (3) remove inconsistent/dead `mb_strimwidth()` fallback branch; (4) evaluate/improve timestamp-ordering test using `sleep(1)` or document why it remains necessary. Reviewer also flagged apparent unrelated task files, but after fetching `origin/main`, `git diff origin/main...HEAD` contains only the expected 8 SESSION-01 files, so no branch pollution fix is needed.
- task-to-pr: reviewer pass completed; launching fork to address actionable suggestions.

## Task workflow update - 2026-06-08T16:49:06.464Z
- Recorded fork run: 8hpsi5tn7cpa
- Validation: Verified worktree clean on branch `task/session-01-session-catalog-and-names` at `d59bd304`.; Verified review-fix commit stat: 2 files changed, 35 insertions, 27 deletions.; Fork validation: `castor test --filter=HatfieldSessionStoreTest` PASS — 23 tests, 95 assertions.; Fork validation: `castor test --filter=SessionAwareModelResolverTest` PASS — 7 tests, 16 assertions.; Fork validation: `castor test` PASS — 2070 tests, 6097 assertions.; Fork validation: `castor deptrac` PASS — 0 violations, 0 errors.; Fork validation: `castor phpstan` PASS — 0 errors, 0 file errors.; Fork validation: `castor cs-check` PASS — clean, 0 files fixed.
- Summary: Review-fix fork completed at commit `d59bd304` (`SESSION-01: Address catalog review findings`). Verified worktree clean at HEAD. Changes address reviewer suggestions: `listSessions()` rows now always include nullable `model`, `model_provider`, `model_name`, and `reasoning` keys; added whitespace-only name clearing coverage; removed inconsistent `mb_strimwidth()` fallback; retained `sleep(1)` in ordering test with stronger rationale because current Symfony Clock package lacks deterministic override helpers.
- task-to-pr: review-fix fork 8hpsi5tn7cpa completed and committed d59bd304; rerunning reviewer on current HEAD.

## Task workflow update - 2026-06-08T16:55:46.581Z
- Validation: Reviewer verdict on `d59bd304`: APPROVE WITH SUGGESTIONS; no critical issues, no issues.; Prior review findings confirmed resolved: stable row shape, whitespace-only name test, mb_strimwidth fallback removal, timestamp-ordering rationale.
- Summary: Final reviewer pass on HEAD `d59bd304` returned APPROVE WITH SUGGESTIONS. Prior findings all resolved and no issues/blockers remain. Remaining actionable/sensible items before strict approval: simplify duplicate `resolvePromptPreview()` call in `HatfieldSessionStore::listSessions()`/`resolveDisplayTitle()`, and refresh/rebase branch onto current `origin/main` to remove two-dot branch-base hygiene noise around unrelated task metadata files. Pre-existing `loadMetadata()` asymmetric nullable key policy is noted as awareness only and not changing because tests/docs intentionally document current contract.
- task-to-pr: launching second review-fix fork for remaining simplify + branch hygiene items before requesting strict APPROVED.

## Task workflow update - 2026-06-08T17:18:22.117Z
- Recorded fork run: qmzc7sgd4u8g
- Validation: Verified worktree clean at HEAD `fb23b07a` on branch `task/session-01-session-catalog-and-names`.; Verified branch commits vs origin/main: `2064d8c3` implementation, `4af49e18` review findings, `fb23b07a` simplify display fallback.; Verified canonical diff `origin/main...HEAD`: 8 expected files only; 510 insertions, 5 deletions.; Reviewer verdict: APPROVED for HEAD `fb23b07a`; no issues or blockers.; Local validation: `castor test` PASS — 2082 tests, 6144 assertions, 0 errors/failures/skipped.; Local validation: `castor deptrac` PASS — 0 violations, 0 errors.; Local validation: `castor phpstan` PASS — 0 errors, 0 file errors.; Local validation: `castor cs-check` PASS — 0 files fixed.
- Summary: Task-to-PR review completed. Second review-fix fork rebased branch onto current `origin/main`, leaving three SESSION-01 commits, and added commit `fb23b07a` simplifying catalog display fallback to compute prompt preview once per row. Canonical PR diff (`origin/main...HEAD`) verified as exactly the expected 8 SESSION-01 files. Strict final reviewer returned APPROVED for HEAD `fb23b07a` with no required/actionable changes. Ready for CODE-REVIEW transition/full Castor gate.
- task-to-pr: second review-fix fork qmzc7sgd4u8g completed at fb23b07a; strict reviewer approved; focused local Castor validation passed; moving to CODE-REVIEW next.

## Task workflow update - 2026-06-08T17:41:12.694Z
- Validation: Reviewer verdict: APPROVED at HEAD `fb23b07a`.; Local focused validation before transition: `castor test` PASS — 2082 tests, 6144 assertions; `castor deptrac` PASS; `castor phpstan` PASS; `castor cs-check` PASS.; CODE-REVIEW transition full gate: FAILED in `test:tui` — TuiAgentSmokeTest timeouts after ~40s with transcript stuck at `◐ Working...`.; Diagnostic rerun: `castor test:tui` FAILED — 5 tests, 9 assertions, 2 errors, 1 failure; same Working timeout/no assistant or error block.; Diagnostic rerun: `castor test:llm-real` FAILED — 5 tests, 24 assertions, 5 failures; failures include `Idle timeout reached for http://192.168.2.38:9052/v1/chat/completions` and queued `llm_<run>` messages.; Second diagnostic rerun: `castor test:llm-real` FAILED again with same endpoint/LLM-real failures.
- Summary: Attempted CODE-REVIEW transition for SESSION-01 at approved HEAD `fb23b07a`. `move_task(to=CODE-REVIEW)` full Castor quality gate failed during TUI E2E: TUI remained at `Working...` and timed out waiting for assistant/error output. Immediate reruns showed environment-level LLM E2E failure unrelated to SESSION-01: `castor test:tui` reproduced the same Working timeout; `castor test:llm-real` failed twice across all 5 LLM-real tests with queued `llm_<run>` messages and `Idle timeout reached for http://192.168.2.38:9052/v1/chat/completions`. Since SESSION-01 touches only session metadata/listing and local unit/integration/deptrac/phpstan/cs-check all pass, no implementation fork was launched. Task remains IN-PROGRESS until the llama.cpp test endpoint/LLM E2E environment recovers and full gate can pass.
- task-to-pr: full Castor gate is blocked by external llama.cpp/LLM E2E endpoint idle timeouts, so task remains IN-PROGRESS; retry CODE-REVIEW transition once LLM E2E environment is healthy.
Castor Check Status: passed
Castor Check Commit: fb23b07a575cddecd030d9901cc5c676a3b0c668
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-08T18:07:31.184Z
Castor Check Output SHA256: 06e0576d6bed89759b1af481fd8bf382f8f35866f86e7f1bd305cc549fdbfa7a

## Task workflow update - 2026-06-08T18:07:35.070Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: fb23b07a575c.
- Pushed task/session-01-session-catalog-and-names to origin.
- branch 'task/session-01-session-catalog-and-names' set up to track 'origin/task/session-01-session-catalog-and-names'.
- Created PR: https://github.com/ineersa/agent-core/pull/108
- Validation: Reviewer verdict: APPROVED at HEAD `fb23b07a`.; Local validation: `castor test` PASS — 2082 tests, 6144 assertions, 0 errors/failures/skipped.; Local validation: `castor deptrac` PASS — 0 violations, 0 errors.; Local validation: `castor phpstan` PASS — 0 errors.; Local validation: `castor cs-check` PASS — 0 files fixed.; Previous full gate blocker: LLM/TUI E2E endpoint idle timeout; user indicated endpoint should be healthy now.
- Summary: SESSION-01 is ready for code review. Strict reviewer approved HEAD `fb23b07a`; focused local validation passed. Previous full-gate failure was diagnosed as external LLM E2E endpoint idle timeout; retrying now after endpoint recovery. Implementation adds nullable session names, migration/runtime migration registration, DB-backed catalog listing API, deterministic display-title fallback, tests, and docs.

## Task workflow update - 2026-06-08T19:14:31.147Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Validation: Read PR #108 inline comments via `gh api repos/ineersa/agent-core/pulls/108/comments --paginate`.; PR comments: HatfieldSession name should be non-null/default from first user message; use Symfony String component for truncation/width; remove sort/order because always same; remove limit entirely.
- Summary: Moving SESSION-01 back to IN-PROGRESS to address PR #108 code review feedback. Inline comments require: make session `name` non-null and default it from first user message stripped/truncated to 200 chars; prefer Symfony String over `mb_strimwidth()`/manual multibyte truncation; remove unused sort/order catalog complexity; remove catalog limit. Main agent remains orchestrator; implementation will be delegated to a fork.

## Task workflow update - 2026-06-08T19:15:37.094Z
- Recorded fork run: s4ontgiphzx7
- Validation: Read all PR #108 inline comments via GitHub API.; Confirmed `symfony/string` is present in composer.lock/vendor, so no dependency edit should be needed.; Moved task CODE-REVIEW → IN-PROGRESS before implementation per workflow.
- Summary: Launched review-iteration fork `s4ontgiphzx7` for PR #108 feedback. Actionable comments classified and delegated: make session `name` non-null and generated from first user prompt capped at 200 chars; replace `mb_strimwidth()` with Symfony String (`symfony/string` already installed); remove dynamic sort/order from catalog query; remove listing limit. Fork instructed to update entity, migration, repository, store, tests, docs, and run Castor validation.
- task-review-iterate: implementation fork s4ontgiphzx7 launched with exact PR feedback instructions.

## Task workflow update - 2026-06-08T19:26:47.097Z
- Recorded fork run: s4ontgiphzx7
- Validation: Verified worktree clean at HEAD `6d78e1f8`.; Verified commit stat: 6 files changed, 253 insertions, 146 deletions.; Verified `origin/main...HEAD` diff: expected 8 SESSION-01 files only; 624 insertions, 12 deletions.; Fork validation: `castor test --filter=HatfieldSessionStoreTest` PASS — 28 tests, 117 assertions.; Fork validation: `castor test --filter=SessionAwareModelResolverTest` PASS — 7 tests, 16 assertions.; Fork validation: `castor test` PASS — 2087 tests, 6166 assertions.; Fork validation: `castor deptrac` PASS — 0 violations.; Fork validation: `castor phpstan` PASS — 0 errors.; Fork validation: `castor cs-check` PASS after `castor cs-fix` import-order correction.
- Summary: Review-iteration fork completed at commit `6d78e1f8` (`SESSION-01: Apply session catalog review feedback`). Verified worktree clean and branch ahead of pushed PR branch by 1 commit. Canonical diff vs `origin/main` remains 8 expected SESSION-01 files. Fork addressed all PR #108 inline comments: non-null `name` column/property capped at 200 and generated from first prompt; Symfony String truncation/normalization; removed sort/order parameters and dynamic repository sort; removed listing limit.
- task-review-iterate: fork s4ontgiphzx7 completed PR feedback fixes at 6d78e1f8; running local validation and reviewer next.
