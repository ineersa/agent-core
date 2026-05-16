# Upgrade Symfony AI packages to 0.9

## Goal
Plan/recon for upgrading this repo from Symfony AI 0.8 to 0.9.

Current direct dependencies in `composer.json` / `composer.lock`:
- `symfony/ai-agent`: constraint `^0.8`, locked `v0.8.0`
- `symfony/ai-platform`: constraint `^0.8`, locked `v0.8.1`

Composer reports 0.9 is available:
- `symfony/ai-agent v0.9.0` requires `symfony/ai-platform ^0.9`
- `symfony/ai-platform v0.9.0` keeps PHP `>=8.2` and Symfony component constraints `^7.3|^8.0`

Recon notes from `/home/ineersa/projects/ai`:
- Local Symfony AI monorepo is on `main` and mostly documents 0.8; only detected 0.9 changelog entry is `src/platform/src/Bridge/Codex/CHANGELOG.md`, which aligns Codex `ModelCatalog` with official OpenAI Codex models (`gpt-5.2` added; `gpt-5.2-codex`, `gpt-5.1-codex`, `gpt-5-codex`, `gpt-5-codex-mini` removed). Agent Core does not appear to use the Codex bridge directly.
- Existing 0.8 BC changes are already relevant context but the repo is already on 0.8 APIs: provider-based `Platform`, typed stream deltas, `#[Schema]` replacement for `#[With]`, array constructors, private traceable properties.

Agent Core Symfony AI usage hotspots:
- `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php` — central streaming bridge; uses `DeferredResult::asStream()`, `DeltaInterface`, `TextDelta`, `ThinkingDelta`, `ThinkingSignature`, `ThinkingComplete`, `ToolCallStart`, `ToolInputDelta`, `ToolCallComplete`, `AssistantMessage`, `ToolCall`, `TokenUsageInterface`, `RawHttpResult`.
- `src/AgentCore/Infrastructure/SymfonyAi/AgentMessageConverter.php` — domain ↔ Symfony AI messages (`MessageBag`, `Message`, `AssistantMessage`, tool call messages).
- `src/AgentCore/Infrastructure/SymfonyAi/DynamicToolDescriptionProcessor.php` — `Symfony\AI\Agent\Input`, `InputProcessorInterface`, `ToolboxInterface`, `Tool` option injection.
- `src/AgentCore/Infrastructure/SymfonyAi/BeforeProviderRequestSubscriber.php` — `InvocationEvent` mutation.
- `src/AgentCore/Infrastructure/SymfonyAi/ModelResolverRoutingSubscriber.php` — `ModelRoutingEvent` mutation.
- `src/AgentCore/Application/Handler/ToolExecutor.php` — `ToolboxInterface`, `FaultTolerantToolbox`, agent `ToolCall`/`ToolResult`, `SourceCollection`.
- Tests/fakes that may require API updates: `tests/AgentCore/Infrastructure/SymfonyAi/PlatformIntegrationTest.php`, `tests/AgentCore/Support/Fake/FakePlatform.php`, `tests/AgentCore/Support/SymfonyAiTestMessages.php`, `tests/AgentCore/Application/Handler/ToolExecutorTest.php`.

Suggested implementation sequence:
1. Update `composer.json` constraints for `symfony/ai-agent` and `symfony/ai-platform` from `^0.8` to `^0.9`.
2. Run dependency resolution/update for just these packages first (`composer update symfony/ai-agent symfony/ai-platform --with-dependencies`, or Castor-equivalent if available) and commit lockfile changes.
3. Inspect installed 0.9 vendor changelogs/source for any additional BC breaks not present in the local monorepo checkout.
4. Run targeted tests/static analysis, patch code/fakes for any API changes, then run full QA.

Risk notes:
- Highest risk is stream delta handling in `LlmPlatformAdapter::buildAssistantMessage()` and fake/manual `Platform` construction in `PlatformIntegrationTest`.
- Current code silently ignores unknown delta classes (`default => null`), so after upgrading, explicitly scan `vendor/symfony/ai-platform/src/Result/Stream/Delta/` for new/removed delta types and decide whether to handle them.

## Acceptance criteria
- `composer.json` requires `symfony/ai-agent` and `symfony/ai-platform` at `^0.9`.
- `composer.lock` is updated to Symfony AI 0.9 packages with no unintended unrelated dependency churn unless required by resolution.
- All compile/test failures caused by Symfony AI 0.9 API changes are fixed in source and tests.
- `LlmPlatformAdapter` streaming delta handling is reviewed against the installed 0.9 delta classes; any new relevant delta types are handled or documented as intentionally ignored.
- Targeted validation passes: `vendor/bin/phpunit tests/AgentCore/Infrastructure/SymfonyAi/PlatformIntegrationTest.php` and `vendor/bin/phpunit tests/AgentCore/Application/Handler/ --filter=ToolExecutor`.
- Full project validation passes with `castor check`.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/2026-05-16-upgrade-symfony-ai-packages-to-0-9
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-upgrade-symfony-ai-packages-to-0-9
Fork run: copbu0v2llm0
PR URL: https://github.com/ineersa/agent-core/pull/6
PR Status: open
Started: 2026-05-16T17:49:49.826Z
Completed:

## Work log
- Created: 2026-05-16T17:47:56.824Z

## Task workflow update - 2026-05-16T17:49:49.826Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-05-16-upgrade-symfony-ai-packages-to-0-9.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-upgrade-symfony-ai-packages-to-0-9.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/2026-05-16-upgrade-symfony-ai-packages-to-0-9.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/2026-05-16-upgrade-symfony-ai-packages-to-0-9.
- Summary: Claiming task for forked implementation.

## Task workflow update - 2026-05-16T17:50:57.855Z
- Implementation fork not launched yet because three forks are already running from this checkout (max concurrent fork limit). Launch next when one active fork completes.

## Task workflow update - 2026-05-16T18:17:31.733Z
- Recorded fork run: copbu0v2llm0
- Fork copbu0v2llm0 launched for implementation in `/home/ineersa/projects/agent-core-worktrees/2026-05-16-upgrade-symfony-ai-packages-to-0-9`.

## Task workflow update - 2026-05-16T18:38:44.959Z
- Validation: `php vendor/bin/phpunit tests/AgentCore/Infrastructure/SymfonyAi/PlatformIntegrationTest.php`: passed (2 tests, 15 assertions).; `php vendor/bin/phpunit tests/AgentCore/Application/Handler/ --filter=ToolExecutor`: passed (5 tests, 17 assertions).; `php vendor/bin/phpunit --exclude-group tui-e2e`: passed (228 tests, 7750 assertions, 1 pre-existing notice).; `vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress`: only pre-existing `.castor/tasks.php:191` short-ternary issue remains.; `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff`: passed.; `castor check`: deptrac passed, phpunit passed, cs-fixer passed; blocked at PHPStan by pre-existing `.castor/tasks.php:191` issue.
- Summary: Fork copbu0v2llm0 completed implementation. Commit `214b4271` on task branch upgrades `symfony/ai-agent` and `symfony/ai-platform` to `^0.9`, updates `composer.lock` with minimal churn (`symfony/ai-agent`, `symfony/ai-platform`, `symfony/string`), adapts source/tests for the Symfony AI 0.9 `AssistantMessage` ContentInterface redesign, and reviews new 0.9 delta types (`BinaryDelta`, `ChoiceDelta`, `MetadataDelta`, `ThinkingStart`) as intentionally ignored by the existing catch-all unless follow-up support is needed.

## Task workflow update - 2026-05-16T18:39:01.023Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/2026-05-16-upgrade-symfony-ai-packages-to-0-9 to origin.
- branch 'task/2026-05-16-upgrade-symfony-ai-packages-to-0-9' set up to track 'origin/task/2026-05-16-upgrade-symfony-ai-packages-to-0-9'.
- Created PR: https://github.com/ineersa/agent-core/pull/6
- Validation: Changed files include `composer.json`, `composer.lock`, Symfony AI adapter/converter/normalizer source, worker/tool-call extraction changes, and tests/fakes updated for Symfony AI 0.9 `AssistantMessage` ContentInterface API.; Validation from fork: PlatformIntegrationTest passed (2 tests, 15 assertions); ToolExecutor tests passed (5 tests, 17 assertions); full PHPUnit excluding tui-e2e passed (228 tests, 7750 assertions); deptrac passed; CS fixer passed; full PHPStan/castor check blocked only by pre-existing `.castor/tasks.php:191` short-ternary issue.
- Summary: Implementation complete in worktree commit `214b4271`. Ready for code review.
