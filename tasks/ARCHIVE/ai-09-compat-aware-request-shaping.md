# AI-09 Apply compat-aware message/options shaping before provider invocation

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-09--apply-compat-aware-messageoptions-shaping-before-provider-invocation

Goal: ensure generic providers receive provider-specific request shapes without scattering compat checks.

Depends on: AI-06; provider path from AI-05 is recommended before final integration.

Parallelism: can run after AI-06; joins with AI-05/AI-10 before full replay validation.

Scope:
- Integrate `ReasoningOptionsResolver` into existing pre-provider hook/subscriber path.
- Add focused mapper for provider/model compat quirks.
- z.ai behavior: no OpenAI `developer` role if `supports_developer_role: false`; send `enable_thinking` for non-off reasoning; do not send `reasoning_effort`; keep tool-call streaming expectation documented by `zai_tool_stream`.
- Keep response parsing in existing Symfony AI 0.9 adapter/converter unless real provider traces prove a gap.

## Acceptance criteria
- Invocation options for `zai/glm-5.1` include `enable_thinking` when reasoning is non-off.
- Invocation options for z.ai never include `reasoning_effort`.
- Message conversion does not emit unsupported developer role for providers that disable it.
- Suggested validation: `castor test --filter Compat`; `castor test --filter PlatformIntegration`.

## Workflow metadata
Status: DONE
Branch: task/ai-09-compat-aware-request-shaping
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-09-compat-aware-request-shaping
Fork run: kaycq4aw1r7n
PR URL: https://github.com/ineersa/agent-core/pull/15
PR Status: merged
Started: 2026-05-17T03:14:30.931Z
Completed: 2026-05-17T21:40:32.684Z

## Work log
- Created: 2026-05-16T22:02:34.212Z

## Task workflow update - 2026-05-17T03:14:30.931Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-09-compat-aware-request-shaping.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-09-compat-aware-request-shaping.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-09-compat-aware-request-shaping.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-09-compat-aware-request-shaping.

## Task workflow update - 2026-05-17T03:15:40.420Z
- Recorded fork run: uyayt31896pf
- Summary: Launched fork uyayt31896pf for AI-09: build CompatRequestShaper (reasoning options via ReasoningOptionsResolver, z.ai enable_thinking, reasoning_effort suppression, developer role suppression), integrate with BeforeProviderRequestSubscriber, focused tests. Logic layer independent of AI-05 Platform.

## Task workflow update - 2026-05-17T03:36:03.334Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-09-compat-aware-request-shaping to origin.
- branch 'task/ai-09-compat-aware-request-shaping' set up to track 'origin/task/ai-09-compat-aware-request-shaping'.
- Created PR: https://github.com/ineersa/agent-core/pull/15
- Summary: AI-09 implemented by fork uyayt31896pf in commit a188b673. Added CompatRequestShaper (112 lines) with BeforeProviderRequestHookInterface, ConfigProvider interface + adapter, auto-registered via _instanceof tag. 11 tests. AI-10 unblocked — can set _hatfield_reasoning in InvocationEvent options.

## Task workflow update - 2026-05-17T21:29:27.615Z
- Recorded fork run: kaycq4aw1r7n
- Summary: Launched fixup fork kaycq4aw1r7n for AI-09 PR #15 after AI-15/AI-16 landed. Scope: merge origin/main, resolve conflicts, remove AppConfigResolverConfigProvider/AppConfigResolver abstraction if no longer needed, inject/use AppConfig directly in CompatRequestShaper, preserve BeforeProviderRequestSubscriber tagged_iterator wiring and AI-16 services.yaml changes, validate and push branch.

## Task workflow update - 2026-05-17T21:33:33.482Z
- Validation: php bin/console --no-interaction: passed; vendor/bin/phpunit tests/CodingAgent/Config/CompatRequestShaperTest.php: passed (11 tests, 29 assertions); vendor/bin/phpunit: passed (341 tests, 8084 assertions, 1 pre-existing notice); vendor/bin/deptrac analyze --config-file=depfile.yaml --no-progress: passed (0 violations); vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress: passed (0 errors; baseline 233); vendor/bin/php-cs-fixer fix and dry-run check: passed; grep AppConfigResolver/ConfigProvider/AppConfigResolverConfigProvider in src: none; main checkout verified clean by fork
- Summary: AI-09 PR #15 fixup completed by fork kaycq4aw1r7n in commit e4b78d56, pushed to branch. Merged origin/main (AI-15/AI-16), resolved services.yaml and phpstan-baseline conflicts, deleted ConfigProvider and AppConfigResolverConfigProvider, rewired CompatRequestShaper to inject HatfieldModelCatalog directly, simplified tests to construct HatfieldModelCatalog directly, preserved behavior and BeforeProviderRequestSubscriber tagged_iterator wiring, regenerated phpstan-baseline.neon.

## Task workflow update - 2026-05-17T21:40:32.684Z
- Moved CODE-REVIEW → DONE.
- Merged task/ai-09-compat-aware-request-shaping into integration checkout.
- Auto-merging config/services.yaml
Auto-merging phpstan-baseline.neon
Merge made by the 'ort' strategy.
 config/services.yaml                               |   7 +
 phpstan-baseline.neon                              |  12 -
 src/CodingAgent/Config/CompatRequestShaper.php     | 114 ++++++++
 .../CodingAgent/Config/CompatRequestShaperTest.php | 287 +++++++++++++++++++++
 4 files changed, 408 insertions(+), 12 deletions(-)
 create mode 100644 src/CodingAgent/Config/CompatRequestShaper.php
 create mode 100644 tests/CodingAgent/Config/CompatRequestShaperTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/ai-09-compat-aware-request-shaping.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #15 merged; Final fixup validation: php bin/console boot passed; CompatRequestShaperTest passed (11 tests, 29 assertions); full PHPUnit passed (341 tests, 8084 assertions, 1 pre-existing notice); deptrac passed; phpstan passed; CS fixer/check passed
- Summary: PR #15 merged. AI-09 complete: compat-aware request shaping integrated, ConfigProvider/AppConfigResolver adapter removed during fixup, CompatRequestShaper now uses HatfieldModelCatalog directly, and branch was updated for AI-15/AI-16/main. Before DONE, committed leftover regenerated PHPStan baseline cleanup (31660567) that the fixup fork validated with but had not committed, removing stale unmatched entries for now-used compatibility/reasoning symbols.
