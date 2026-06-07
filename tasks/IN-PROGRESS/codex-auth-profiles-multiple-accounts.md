# Codex OAuth auth profiles for multiple OpenAI accounts

## Goal
Implement explicit Codex OAuth profiles so users can authenticate and switch between multiple paid OpenAI/Codex accounts without overwriting the default auth record.

Context:
- Current ~/.hatfield/auth.json is a flat map keyed by provider/auth key and CodexAuthStorage already supports multiple keys.
- Current command/service/factory hardcode CodexOAuthConfig::PROVIDER_KEY (`openai-codex`), so `auth:codex` overwrites the previous account and all type:codex providers use the same credentials.
- Desired UX: keep `hatfield auth:codex` as the default single-account flow, and add profile support such as `hatfield auth:codex --profile work` / `--profile personal`.
- Provider config should be able to select credentials explicitly, e.g. `auth_key: openai-codex-work`, with a sensible default fallback.

Suggested design:
- Add a Codex provider auth-key/profile field to config DTOs (e.g. `auth_key` on AiProviderConfig) and docs/defaults.
- Add `--profile <name>` to `auth:codex`; map profiles to storage keys like `openai-codex-<profile>` while preserving default `openai-codex` when omitted.
- Optionally add `--auth-key <key>` if profile names are too limiting; validate allowed characters and avoid path/file semantics.
- Update CodexOAuthService and CodexTokenRefresher calls to accept the selected storage key instead of hardcoding `openai-codex`.
- Update SymfonyAiProviderFactory to load Codex credentials by `$provider->authKey ?? $provider->id ?? CodexOAuthConfig::PROVIDER_KEY` (or another agreed precedence), while preserving OAuth-only Codex credentials.
- Ensure refresh locking and account-id mismatch validation remain per stored record.
- Document examples for personal/work accounts in docs/settings.md and config/hatfield.defaults.yaml.

Notes:
- Do not reintroduce YAML api_key/account_id support for Codex.
- Multiple OAuth refresh tokens for the same OAuth client and different OpenAI users should be stored independently; avoid logging token/account values.

## Acceptance criteria
- `auth:codex` without profile preserves current behavior and writes/refreshes `openai-codex`.
- `auth:codex --profile <name>` stores credentials under a distinct deterministic key such as `openai-codex-<name>` without overwriting the default account.
- Multiple Codex providers in settings can select different stored credentials via an explicit config key (e.g. `auth_key`) or documented provider-id mapping.
- Provider factory loads Codex credentials using the provider-selected auth key and keeps Codex OAuth-only semantics.
- Token refresh, file locking, permissions, and account-id mismatch checks remain correct per profile/key.
- Docs and defaults show a two-account example and explain overwrite/default behavior.
- Focused tests cover storage coexistence, command/service profile key selection, provider-factory credential selection, and default backward-compatible behavior.
- Castor validation passes: focused tests, phpstan on changed auth/config/provider files, deptrac, cs-check.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/codex-auth-profiles-multiple-accounts
Worktree: /home/ineersa/projects/agent-core-worktrees/codex-auth-profiles-multiple-accounts
Fork run: r0lndgf0ic6h
PR URL: https://github.com/ineersa/agent-core/pull/100
PR Status: open
Started: 2026-06-07T22:22:43.380Z
Completed:

## Work log
- Created: 2026-06-07T21:26:12.410Z

## Task workflow update - 2026-06-07T21:34:07.019Z
- Summary: Policy/risk note added after research: profile support should be explicit/manual account selection, not automatic failover/load-balancing when one account reaches a cap. Using a second paid account after the first hits a cap is the highest-risk interpretation because OpenAI policies prohibit circumventing rate/usage limits; implementation should avoid auto-switch-on-cap behavior and document that profiles are for separate accounts/purposes, with user-controlled switching.

## Task workflow update - 2026-06-07T21:37:01.242Z
- Summary: Policy/product clarification from user: support manual provider/profile switching only. The implementation must NOT add automatic failover, retry-on-limit, round-robin, load balancing, or any behavior that switches accounts when one hits a cap. Users may manually select a different configured Codex provider/profile (for example via model/provider selection) when desired. Document this as explicit/manual profile selection for separate paid accounts, not automated usage-limit circumvention.

## Task workflow update - 2026-06-07T22:22:43.380Z
- Moved TODO → IN-PROGRESS.
- Created branch task/codex-auth-profiles-multiple-accounts.
- Created worktree /home/ineersa/projects/agent-core-worktrees/codex-auth-profiles-multiple-accounts.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/codex-auth-profiles-multiple-accounts.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/codex-auth-profiles-multiple-accounts.

## Task workflow update - 2026-06-07T22:24:13.991Z
- Validation: Scout only; no implementation yet.
- Summary: Task claimed and worktree created at /home/ineersa/projects/agent-core-worktrees/codex-auth-profiles-multiple-accounts. Read-only scout confirmed CodexAuthStorage already supports multiple provider keys, but CodexOAuthService login/refresh and SymfonyAiProviderFactory currently hardcode/default to CodexOAuthConfig::PROVIDER_KEY (`openai-codex`), CodexAuthCommand has no --profile option, and AiProviderConfig has no auth_key field. Implementation plan: add config auth_key, add CLI --profile mapped to openai-codex-<profile> with validation, pass selected key through CodexOAuthService login/refresh, load provider credentials by explicit auth_key with default fallback, update docs/defaults, and add focused tests. Keep manual provider/profile switching only; no auto failover or retry-on-limit behavior.

## Task workflow update - 2026-06-07T22:25:45.146Z
- Recorded fork run: 9g5xn40wapel
- Summary: Launched implementation fork 9g5xn40wapel in worktree /home/ineersa/projects/agent-core-worktrees/codex-auth-profiles-multiple-accounts with instructions to implement explicit Codex OAuth profiles, auth_key provider selection, docs/defaults, focused tests, and Castor-only validation. Stop boundary: no PR/code-review/push/castor check/reviewer.

## Task workflow update - 2026-06-07T22:30:37.197Z
- Recorded fork run: 9g5xn40wapel
- Validation: Verified commit exists in worktree: 7077795b on branch task/codex-auth-profiles-multiple-accounts.; Verified worktree clean via git status --short.; Verified diff/stat expected: config/hatfield.defaults.yaml, docs/settings.md, Codex auth/service/command/config/provider factory files, and focused auth/provider tests changed.; Fork validation reported: castor test --filter='CodexAuthConfig|CodexOAuth|AiProviderConfig|SymfonyAiProviderFactory' passed (49 tests, 87 assertions).; Fork validation reported: castor test --filter='CodexAuthConfig|CodexOAuth|AiProviderConfig|SymfonyAiProviderFactory|CodexAuth' passed (63 tests, 118 assertions).; Fork validation reported: castor phpstan --path src/CodingAgent/Auth passed (0 errors).; Fork validation reported: castor phpstan --path src/CodingAgent/CLI/Auth passed (0 errors).; Fork validation reported: castor phpstan --path src/CodingAgent/Config/Ai passed (0 errors).; Fork validation reported: castor phpstan --path src/CodingAgent/Infrastructure/SymfonyAi passed (0 errors).; Fork validation reported: castor deptrac passed (0 violations).; Fork validation reported: castor cs-fix ran (2 files fixed) and castor cs-check passed clean.
- Summary: Implementation fork completed successfully at commit 7077795b (`feat(coding-agent): add Codex OAuth profiles for multiple accounts`). Verified worktree is clean and diff matches expected scope: 11 files changed, 352 insertions, 18 deletions. Implemented `auth:codex --profile <name>` profile-to-key mapping (`openai-codex-<profile>`), provider-level `auth_key` config, CodexOAuthService provider-key login/refresh, SymfonyAiProviderFactory credential selection by auth_key with default fallback, docs/defaults updates, and focused tests. No automatic failover/retry-on-limit/load-balancing behavior added; Codex remains OAuth-only.

## Task workflow update - 2026-06-07T23:02:00.043Z
- Recorded fork run: 123g05h92w0w
- Validation: Reviewer verdict: APPROVE WITH SUGGESTIONS on 7077795b; no critical issues.
- Summary: Reviewer returned APPROVE WITH SUGGESTIONS for HEAD 7077795b with no critical/bug/security blockers. Actionable findings: improve profile-specific missing-credential hints in CodexOAuthService, avoid misleading `--profile=` hints for malformed/custom auth_key values in SymfonyAiProviderFactory, simplify duplicate SymfonyStyle construction in CodexAuthCommand, clarify providerKeyForProfile docblock, and add focused tests for empty/malformed auth_key/profile hints. Launched fix fork 123g05h92w0w to address all sensible suggestions before re-review.

## Task workflow update - 2026-06-07T23:09:35.040Z
- Recorded fork run: ut2bh7fej4gj
- Validation: Reviewer verdict on ab8dca9d: APPROVE WITH SUGGESTIONS; no critical/security/blocking issues.
- Summary: Re-review of HEAD ab8dca9d returned APPROVE WITH SUGGESTIONS: all prior findings fixed, with remaining actionable consistency/cosmetic items. Launched fix fork ut2bh7fej4gj to make CodexAuthStorage auto-refresh failures use profile-aware auth command hints, wrap CodexOAuthService explicit refresh failures with provider-key/profile context, avoid blank profile label for `--profile ""`, and add focused tests.

## Task workflow update - 2026-06-07T23:18:26.681Z
- Recorded fork run: ut2bh7fej4gj
- Validation: Reviewer verdict on HEAD 0e70332f: APPROVE; no critical/security/correctness issues.; Focused validation on worktree passed: castor test => tests=1977, assertions=5766, errors=0, failures=0, skipped=0; PHAR smoke OK.; Focused validation on worktree passed: castor deptrac => violations=0, errors=0, uncovered=717, allowed=901.; Focused validation on worktree passed: castor phpstan => errors=0, file_errors=0.; Focused validation on worktree passed: castor cs-check => files_fixed=0.; Verified git status clean on branch task/codex-auth-profiles-multiple-accounts at 0e70332f.
- Summary: Final reviewer pass on HEAD 0e70332f returned APPROVE with no issues. Verified task worktree is clean. Branch contains three task commits: 7077795b initial profile/auth_key implementation, ab8dca9d first review-suggestion cleanup, 0e70332f profile-aware refresh error hint cleanup. Ready for CODE-REVIEW move and Castor quality gate.
Castor Check Status: passed
Castor Check Commit: 0e70332f6f5f581dc69036486d6d683f01fcb64d
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-07T23:20:55.941Z
Castor Check Output SHA256: 26180184825ff62806f719a2d50976c001d18700360ac193fe105e023cf6ad02

## Task workflow update - 2026-06-07T23:20:59.327Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: 0e70332f6f5f.
- Pushed task/codex-auth-profiles-multiple-accounts to origin.
- branch 'task/codex-auth-profiles-multiple-accounts' set up to track 'origin/task/codex-auth-profiles-multiple-accounts'.
- Created PR: https://github.com/ineersa/agent-core/pull/100

## Task workflow update - 2026-06-07T23:35:28.260Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Review-iteration needed after user smoke test: PHAR `auth:codex --profile canada` and `auth:codex --help` fail because Symfony Console already has a global `--profile` option, producing `An option named "profile" already exists.` Need rename the command-specific option away from `--profile` and update docs/tests/errors accordingly.

## Task workflow update - 2026-06-07T23:36:07.897Z
- Recorded fork run: r0lndgf0ic6h
- Validation: User smoke failure reproduced conceptually: command-specific `--profile` conflicts with Symfony Console global `--profile`.
- Summary: Launched review-iteration fork r0lndgf0ic6h to fix user smoke failure: Symfony Console already owns global `--profile`, causing `auth:codex --help` and `auth:codex --profile canada` to fail with `An option named "profile" already exists.` Fork will rename Codex profile option to `--auth-profile`, update command hints/docs/tests/default comments, run focused Castor validation plus PHAR smoke (`auth:codex --help`, invalid auth-profile).
