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
Fork run: z0lk809uwmes
PR URL: https://github.com/ineersa/agent-core/pull/98
PR Status: open
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

## Task workflow update - 2026-06-06T21:26:23.987Z
- Recorded fork run: kpi1pars94rl
- Validation: fork: castor test --filter=Codex passed (61 tests, 156 assertions); fork: castor test passed (1816 tests, 5322 assertions); fork: castor deptrac passed (0 violations); fork: castor cs-fix then castor cs-check passed clean; fork: castor phpstan src/CodingAgent/Auth reported 4 errors in auth files; pending remediation before PR prep can complete
- Summary: Fix fork kpi1pars94rl completed at commit 3358743a `fix(coding-agent): harden Codex auth review findings`. It implemented reviewer fixes for token expiry seconds, timeout/port handling, refresh account-id validation, catch/storage hardening, GenericProvider config centralization, missing ArgvInput import, and docs/settings auth guidance. Worktree is clean. Fork also reported 4 PHPStan errors in new auth files remain; although described as pre-existing relative to that fork, they are branch-introduced and must be resolved before CODE-REVIEW quality gate.
- Fix fork created intermediate commit 82dc8cca and final commit 3358743a; final tree state is clean
- Remaining gate risk from fork handoff: PHPStan errors in CodexAccountIdExtractor, CodexOAuthService, and LocalCallbackServer

## Task workflow update - 2026-06-06T21:39:06.354Z
- Recorded fork run: ypp7l8z96ssa
- Summary: Second re-review returned REQUEST CHANGES. Launched fix fork ypp7l8z96ssa to address remaining blockers: callback server must listen before browser opens, local callback must require matching state, branch PHPStan errors must be fixed, dead REDIRECT_URI constant removed, refresh logic/race refactored into shared refresher with locking, and deptrac layer coverage added for new Auth directory.
- Prior findings confirmed fixed: expiry units, timeout passthrough, custom port redirect, account-id validation, storage write hardening, GenericProvider config centralization, ArgvInput import, docs/settings guidance
- Remaining actionable findings passed to fork: callback race, missing state validation, PHPStan gate blockers, dead BC constant, refresh race/duplication, deptrac coverage

## Task workflow update - 2026-06-06T21:47:11.118Z
- Recorded fork run: ypp7l8z96ssa
- Validation: fork: castor test --filter=Codex passed (66 tests, 165 assertions); fork: castor test passed (1821 tests, 5331 assertions); fork: castor deptrac passed (0 violations, 0 errors); fork: castor phpstan passed (0 errors, 0 file errors); fork: castor cs-check passed clean; orchestrator inspection: git status clean; HEAD b38845b0; diff stat origin/main...HEAD shows 31 files changed including preserved PHAR isolation and Codex auth implementation/fixes
- Summary: Second fix fork ypp7l8z96ssa completed at commit b38845b0 `fix(coding-agent): resolve Codex auth review blockers`. It resolved the callback-server/browser race, strict local-callback state validation, PHPStan errors, dead REDIRECT_URI constant, duplicate/racy refresh logic via new CodexTokenRefresher, deptrac coverage for AppAuth, and retained secure storage writes. Worktree verified clean at HEAD b38845b0.
- Added new src/CodingAgent/Auth/CodexTokenRefresher.php and deptrac AppAuth/SymfonyProcess layer coverage
- Known skipped item from fork: LocalCallbackServer bind-failure logger injection skipped as low ROI; fallback to manual paste remains and URL is printed

## Task workflow update - 2026-06-06T21:54:45.755Z
- Recorded fork run: y7xo7dgh05j8
- Summary: Final reviewer pass returned APPROVE WITH SUGGESTIONS and confirmed all prior blockers fixed. Launched cleanup fork y7xo7dgh05j8 to address remaining actionable suggestions: require callback `code` to be a string, remove duplicate bare-code parser test, normalize test spacing, and add concise comments for raw credential load / 8KB local callback read if useful.
- Reviewer prior blockers confirmed fixed: browser/server ordering, strict state validation, PHPStan, dead REDIRECT_URI, refresh race/duplication, deptrac AppAuth coverage
- Remaining findings are non-blocking but actionable, so cleanup fork launched before final approval/validation

## Task workflow update - 2026-06-06T21:57:39.133Z
- Recorded fork run: y7xo7dgh05j8
- Validation: fork: castor test --filter=Codex passed (66 tests, 165 assertions); fork: castor phpstan scoped reported no new errors; only pre-existing staticMethod.dynamicCall in tests per fork handoff; fork: castor deptrac passed (0 violations, 0 errors); fork: castor cs-check passed clean; orchestrator inspection: git status clean; HEAD fab4f4bf
- Summary: Cleanup fork y7xo7dgh05j8 completed at commit fab4f4bf `fix(coding-agent): polish Codex auth review suggestions`. It addressed the final APPROVE WITH SUGGESTIONS items: LocalCallbackServer now requires `code` to be a non-empty string, duplicate ManualCodeParser bare-code test removed, CodexAuthRecordTest spacing normalized, and a concise 8KB local OAuth callback read comment added. Worktree verified clean at HEAD fab4f4bf.
- Skipped CodexAuthStorage::loadCredentialsRaw() comment because existing docblock already states it loads without auto-refresh and is used by explicit refresh

## Task workflow update - 2026-06-06T22:03:16.370Z
- Recorded fork run: c3d5le8re4r7
- Validation: orchestrator: castor test passed (1820 tests, 5329 assertions, 0 errors/failures); orchestrator: castor deptrac passed (0 violations, 0 errors); orchestrator: castor phpstan failed with 1 error: LocalCallbackServer.php cast.useless
- Summary: Focused local validation after reviewer approval failed at `castor phpstan` with one branch error: `src/CodingAgent/Auth/LocalCallbackServer.php` line 126 `[cast.useless] Casting to string something that's already string.` Launched fix fork c3d5le8re4r7 to remove the redundant cast and rerun Castor validation.
- Need re-run validation after c3d5le8re4r7 completes; do not move to CODE-REVIEW until full focused validation passes

## Task workflow update - 2026-06-06T22:04:17.163Z
- Recorded fork run: c3d5le8re4r7
- Validation: fork: castor test --filter=Codex passed (66 tests, 165 assertions); fork: castor phpstan passed (0 errors, 0 file errors); fork: castor deptrac passed (0 violations, 0 errors); fork: castor cs-check passed clean; orchestrator inspection: git status clean; HEAD 25eba5a7
- Summary: Validation-fix fork c3d5le8re4r7 completed at commit 25eba5a7 `fix(coding-agent): remove redundant Codex callback cast`. It removed the `(string)` cast from LocalCallbackServer now that the code parameter is already guarded as a string. Worktree verified clean at HEAD 25eba5a7.
- One-line semantic no-op fix resolved PHPStan cast.useless blocker found by focused validation

## Task workflow update - 2026-06-06T22:06:13.497Z
- Validation: reviewer: APPROVE at HEAD 25eba5a7; no blockers, no remaining issues; castor test passed (1820 tests, 5329 assertions, 0 errors/failures); castor deptrac passed (0 violations, 0 errors, uncovered=672, allowed=852); castor phpstan passed (0 errors, 0 file errors); castor cs-check passed (files_fixed=0)
- Summary: PR-prep review completed for exact HEAD 25eba5a7. Reviewer verdict: APPROVE. The final reviewer confirmed the only post-approval change was the one-line redundant-cast removal in LocalCallbackServer and that it has no behavioral/security impact. Worktree is clean and ready for CODE-REVIEW gate/PR creation.
- Task-to-PR review iterations: initial REQUEST CHANGES fixed by commits 3358743a/82dc8cca; second REQUEST CHANGES fixed by b38845b0; APPROVE WITH SUGGESTIONS fixed by fab4f4bf; final validation PHPStan cast.useless fixed by 25eba5a7
- Branch still intentionally contains preserved PHAR isolation commit 94853790 per user instruction
Castor Check Status: passed
Castor Check Commit: 25eba5a7c5b507b2d73a5a0451065f7df3e1586e
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-06T22:08:48.084Z
Castor Check Output SHA256: 271e527f441554fd2f242bcdfa1bab8fccc6951b56a9d90f88eaedd81fc1a1c9

## Task workflow update - 2026-06-06T22:08:51.794Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: 25eba5a7c5b5.
- Pushed task/hatfield-openai-codex-auth-research to origin.
- branch 'task/hatfield-openai-codex-auth-research' set up to track 'origin/task/hatfield-openai-codex-auth-research'.
- Created PR: https://github.com/ineersa/agent-core/pull/98
- Validation: reviewer APPROVE at HEAD 25eba5a7; castor test passed (1820 tests, 5329 assertions); castor deptrac passed (0 violations, 0 errors); castor phpstan passed (0 errors, 0 file errors); castor cs-check passed clean
- Summary: Prepared for PR/code review. Reviewer approved exact HEAD 25eba5a7 after multiple remediation forks. Focused local Castor validation passed: test, deptrac, phpstan, cs-check. Branch includes Codex OAuth auth implementation plus preserved PHAR isolation commit per user instruction.

## Task workflow update - 2026-06-06T22:59:37.168Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Validation: PR inline comment reviewed: src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php line 103 — drop explicit YAML credential configs for Codex
- Summary: Review iteration requested from PR #98 inline comment: Codex credentials should not be manually configured via YAML because OAuth is required first. Plan: remove YAML api_key/account_id override path for Codex, rely only on auth:codex stored credentials, and update defaults/docs/tests accordingly.

## Task workflow update - 2026-06-06T23:00:05.098Z
- Recorded fork run: z0lk809uwmes
- Summary: Launched review-iteration fork z0lk809uwmes to address PR #98 inline comment: remove manual YAML credential support/override semantics for Codex and require credentials from `auth:codex` / `~/.hatfield/auth.json`. Scope includes SymfonyAiProviderFactory Codex credential resolution, defaults/docs cleanup, and factory tests update.
- Do not push or move task until fork completes, changes are verified, reviewer re-run, and focused Castor validation passes
