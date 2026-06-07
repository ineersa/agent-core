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
Fork run:
PR URL:
PR Status:
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
