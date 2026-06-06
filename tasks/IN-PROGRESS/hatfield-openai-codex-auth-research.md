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
Fork run:
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
