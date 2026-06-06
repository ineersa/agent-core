# Research OpenAI Codex auth flow for Hatfield

## Goal
Investigate how pan/pi-mono implement OpenAI Codex OAuth/PKCE auth, token storage, refresh flow, account-id extraction, and overall auth process so Hatfield can add a user-auth-ready Codex provider. Include whether an existing Symfony/PHP OAuth PKCE library could replace a fully hand-rolled implementation.

## Acceptance criteria
- Document pan/pi-mono Codex OAuth/PKCE flow with exact files, classes, and command flow
- Document token creation, refresh, persistence format, and account-id extraction
- Identify candidate Symfony/PHP OAuth/PKCE libraries suitable for a local CLI auth flow and assess fit
- Recommend Hatfield implementation shape and follow-up task scope for auth:codex, refresh, and auth.json storage

## Workflow metadata
Status: IN-PROGRESS
Branch: task/hatfield-openai-codex-auth-research
Worktree: /home/ineersa/projects/agent-core-worktrees/hatfield-openai-codex-auth-research
Fork run: kpi1pars94rl
PR URL:
PR Status:
Started: 2026-06-06T17:25:57.754Z
Completed:

## Work log
- Created: 2026-06-06T17:25:53.157Z

## Task workflow update - 2026-06-06T17:25:57.754Z
- Moved TODO → IN-PROGRESS.
- Created branch task/hatfield-openai-codex-auth-research.
- Created worktree /home/ineersa/projects/agent-core-worktrees/hatfield-openai-codex-auth-research.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/hatfield-openai-codex-auth-research.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/hatfield-openai-codex-auth-research.

## Task workflow update - 2026-06-06T17:30:34.101Z
- Summary: Created and claimed follow-up research task for Codex user-auth support. Parallel scouts completed across pi-mono and external library research.

Key findings:
- No `pan` package found; Codex auth implementation lives in pi-mono under `packages/ai/src/utils/oauth/openai-codex.ts` with TUI integration in `packages/coding-agent`.
- pi-mono uses OAuth authorization_code + PKCE with a local callback server on `127.0.0.1:1455/auth/callback`, browser auto-open, and manual paste fallback.
- OpenAI Codex constants in pi-mono: client_id `app_EMoamEEZ73f0CkXaXp7hrann`, authorize URL `https://auth.openai.com/oauth/authorize`, token URL `https://auth.openai.com/oauth/token`, scope `openid profile email offline_access`.
- Token exchange uses `application/x-www-form-urlencoded`; refresh uses the same token endpoint with `grant_type=refresh_token`.
- `chatgpt_account_id` is extracted from the access-token JWT claim path `https://api.openai.com/auth.chatgpt_account_id`.
- Credentials are stored in `~/.pi/agent/auth.json` (or `$PI_CODING_AGENT_DIR/auth.json`) keyed by provider ID with fields `{type:"oauth", access, refresh, expires, accountId}`.
- pi-mono wraps refresh in a lock around auth.json using proper-lockfile, re-reads before refresh, writes back with `0600`, and tolerates another instance refreshing first.
- Library research: no Symfony bundle cleanly fits a CLI/native-app loopback PKCE flow. Best partial fit is `league/oauth2-client` for PKCE + token exchange, while hand-rolling the loopback callback server, browser launcher, and local token storage.

Recommended direction for Hatfield:
1. Build `auth:codex` around a minimal native-app PKCE flow.
2. Reuse pi-mono flow shape: browser launch + localhost callback + manual paste fallback.
3. Store credentials in `~/.hatfield/auth.json` (0600) with lock/refresh semantics similar to pi-mono.
4. Consider `league/oauth2-client` only as a protocol helper; do not pull in web-oriented Symfony OAuth bundles.
- Created task and moved to IN-PROGRESS
- Launched 3 parallel scouts: pi-mono auth flow, token storage/refresh/account-id extraction, and Symfony/PHP OAuth library research
- Scouts completed; key exact files: packages/ai/src/utils/oauth/openai-codex.ts, packages/ai/src/utils/oauth/pkce.ts, packages/coding-agent/src/core/auth-storage.ts, packages/coding-agent/src/modes/interactive/components/login-dialog.ts
- Research recommendation: minimal hand-rolled CLI flow with optional league/oauth2-client assistance; avoid web-only Symfony OAuth bundles

## Task workflow update - 2026-06-06T17:45:12.870Z
- Summary: User decision: use `league/oauth2-client` as the OAuth/PKCE helper for the future Codex auth implementation. Keep CLI-specific pieces (loopback callback server, browser launch, manual paste fallback, local auth.json storage/locking) in Hatfield code.
- User agreed on `league/oauth2-client` as the preferred protocol helper
- Direction clarified: use league/oauth2-client for PKCE + token exchange, while hand-rolling Hatfield CLI/native-app pieces around it

## Task workflow update - 2026-06-06T17:47:18.776Z
- Validation: fork validation: castor phar:clean && castor phar:ensure OK; PHAR built at worktree-local var/tmp/phar/hatfield.phar; staging at var/tmp/phar-build/source; smoke passed; fork validation: castor test OK (1782 tests, 5263 assertions, 0 errors, 0 failures); fork validation: castor deptrac OK (0 violations, 0 errors); fork validation: castor phpstan OK (0 errors, 0 file errors); fork validation: castor cs-fix then castor cs-check OK (1 file fixed, 0 remaining); post-fork inspection: HEAD 94853790; 9 files changed; worktree clean
- Summary: Fork implemented worktree-local PHAR build/test isolation. Commit 94853790 `fix(phar): isolate QA builds per worktree` changes default Castor PHAR output from global `/tmp/bin/hatfield.phar` to `<worktree>/var/tmp/phar/hatfield.phar` and staging from `/tmp/hatfield-phar-build/source` to `<worktree>/var/tmp/phar-build/source`. `HATFIELD_PHAR_PATH` and `HATFIELD_PHAR_STAGING_DIR` remain explicit build overrides; `HATFIELD_BINARY_PATH` runtime override semantics are unchanged. Castor E2E/test tasks that call `phar_ensure()` (`test:tui`, `test:controller`, `test:llm-real`, `test:tui-update`, and `check` transitively) now receive the worktree-local PHAR by default. Docs and test comments updated; containment assertions added in `.castor/helpers.php`. Worktree is clean; local worktree PHAR exists at `var/tmp/phar/hatfield.phar`; global `/tmp/bin/hatfield.phar` was not touched by cleanup.

## Task workflow update - 2026-06-06T17:49:12.672Z
- Validation: No new validation run for this correction note. Prior fork validation for 94853790 remains recorded above: phar:clean/ensure, castor test, deptrac, phpstan, cs-check all passed.
- Summary: Correction note: PHAR worktree-local build/test isolation is unrelated to the OpenAI Codex auth research task and was implemented in this task worktree by orchestration mistake. User explicitly objected to the cross-task contamination, then instructed to keep the commit here for now and update the task file. Current local code commit on this task branch: 94853790 `fix(phar): isolate QA builds per worktree` (not pushed by the main agent). Treat this as an incidental branch payload that must be accounted for during the eventual task-to-pr/review, not as part of the original Codex auth research scope. No task transition, no PR, no branch push performed.

## Task workflow update - 2026-06-06T17:49:54.741Z
- User constraint: preserve existing unrelated changes in this worktree (task-file updates and PHAR isolation changes). Do not reset/clean/remove them during auth implementation.

## Task workflow update - 2026-06-06T17:52:42.987Z
- Summary: Task scope expanded in-place from research to implementation. We will implement a v1 user-auth-ready Codex flow in this existing worktree using league/oauth2-client as the OAuth/PKCE helper, while keeping Hatfield-specific CLI pieces (callback server, browser launch, manual fallback, auth.json storage/locking) in local code.
- Repurposed current task in-place from research-only to implementation; no new task/worktree will be created
- Implementation target: auth:codex command + ~/.hatfield/auth.json storage + refresh-on-read + provider-factory fallback to stored Codex credentials
- Constraint remains: preserve existing unrelated branch/worktree changes; do not reset/clean/remove them

## Task workflow update - 2026-06-06T17:53:33.832Z
- Recorded fork run: th4dmot63q4t
- Summary: Launched implementation fork th4dmot63q4t in the existing auth-research worktree to add v1 user-auth-ready Codex support: auth:codex command, PKCE flow, loopback callback server, browser/manual fallback, ~/.hatfield/auth.json storage + refresh-on-read, and provider-factory fallback to stored credentials.
- Gathered local implementation context: CLI command pattern, SettingsPathResolver/HomeSettingsWriter, LockFactory/session-store patterns, provider-factory integration point
- User confirmed league/oauth2-client direction
- Implementation fork launched with explicit preserve-existing-branch-changes constraint

## Task workflow update - 2026-06-06T18:05:01.087Z
- Recorded fork run: th4dmot63q4t
- Validation: fork: composer require league/oauth2-client ^2.8 completed; composer.json/composer.lock updated; fork: castor test --filter=Codex passed (56 tests, 145 assertions); fork: castor test passed (1811 tests, 5311 assertions, 0 errors/failures); fork: castor deptrac passed (0 violations, 0 errors); fork: castor phpstan scoped passed with no new errors; fork reported 23 pre-existing staticMethod.dynamicCall findings only; fork: castor cs-fix then castor cs-check passed clean; orchestrator inspection: git status clean on task/hatfield-openai-codex-auth-research; HEAD f1466c98; diff stat origin/main...HEAD shows Codex auth implementation plus preserved prior PHAR isolation payload
- Summary: Implementation fork th4dmot63q4t completed and committed f1466c98 `feat(coding-agent): add Codex OAuth auth flow and credential storage`. Added v1 user-auth-ready Codex support: `auth:codex` CLI command, OAuth PKCE flow via league/oauth2-client, loopback callback server, browser/manual fallback, `~/.hatfield/auth.json` storage with 0600 + locking, auto-refresh-on-read, account-id extraction from JWT, and SymfonyAiProviderFactory fallback to stored credentials when YAML `api_key`/`account_id` are missing. Post-fork inspection: worktree clean at HEAD f1466c98. Branch also still contains the previously recorded incidental PHAR isolation commit 94853790, per user instruction to preserve it.
- Files created by fork: src/CodingAgent/Auth/{BrowserLauncher,CodexAccountIdExtractor,CodexAuthRecord,CodexAuthStorage,CodexOAuthConfig,CodexOAuthService,LocalCallbackServer,ManualCodeParser}.php; src/CodingAgent/CLI/Auth/CodexAuthCommand.php; 5 Codex auth/factory test files
- Files modified by fork: composer.json, composer.lock, config/services.yaml, config/hatfield.defaults.yaml, src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php
- Known out-of-scope/follow-up from fork handoff: TUI login dialog, slash-command login, mid-session token hot-swap; fork also noted auth docs in docs/settings.md as a future documentation follow-up

## Task workflow update - 2026-06-06T21:17:14.574Z
- Recorded fork run: kpi1pars94rl
- Summary: Reviewer returned REQUEST CHANGES for PR prep. Launched fix fork kpi1pars94rl to address all actionable findings: seconds-vs-milliseconds token expiry mismatch, ignored timeout, custom port/redirect mismatch, refresh account-id validation, catch/logging/storage permission hardening, GenericProvider config deduplication, missing ArgvInput import, and docs/settings auth guidance.
- Reviewer findings included CRITICAL token expiry unit mismatch causing credentials to appear always expired; BUGs in timeout/port handling and refresh account-id docblock; SECURITY/CONVENTION issues around auth storage permissions/TOCTOU and silent catches; SIMPLIFY duplicated GenericProvider config
- Fix fork instructed to preserve branch payload including incidental PHAR isolation commit
