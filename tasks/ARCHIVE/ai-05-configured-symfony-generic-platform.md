# AI-05 Build configured Symfony generic providers/platform

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-05--build-configured-symfony-generic-providersplatform

Goal: instantiate a single multi-provider Symfony AI Platform from Hatfield settings.

Depends on: AI-04.

Parallelism: can run after AI-04 while AI-10 preparation continues.

Scope:
- Build `SymfonyAiProviderFactory` using Symfony generic bridge factory.
- Build provider registry keyed by Hatfield provider ID.
- Build `ConfiguredSymfonyAiPlatformFactory` returning `Symfony\AI\Platform\Platform` with enabled configured providers, projected catalogs, and event dispatcher passed into Platform/provider construction.
- Wire DI aliases for Symfony `PlatformInterface` and AgentCore `PlatformInterface` adapter.
- Bind safe configured default model for `ExecuteLlmStepWorker::$defaultModel` until per-turn routing overrides it.
- DeepSeek uses generic provider with `base_url: https://api.deepseek.com` and `completions_path: /chat/completions`.

## Acceptance criteria
- Container compiles with configured generic providers.
- Existing fake/provider tests still pass.
- No `symfony/ai-deep-seek-platform` dependency is required.
- Suggested validation: `castor test --filter Platform`; `castor deptrac`.

## Workflow metadata
Status: DONE
Branch: task/ai-05-configured-symfony-generic-platform
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform
Fork run: p929smwf7g9q
PR URL: https://github.com/ineersa/agent-core/pull/14
PR Status: merged
Started: 2026-05-17T03:14:13.694Z
Completed: 2026-05-17T21:38:29.072Z

## Work log
- Created: 2026-05-16T22:01:55.475Z

## Task workflow update - 2026-05-17T03:14:13.694Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-05-configured-symfony-generic-platform.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform.

## Task workflow update - 2026-05-17T03:15:40.420Z
- Recorded fork run: 5zbba9h6tm9z
- Summary: Launched fork 5zbba9h6tm9z for AI-05: build SymfonyAiProviderFactory creating generic providers from Hatfield config, ConfiguredSymfonyAiPlatformFactory wrapping Platform with EventDispatcher, DI wiring for PlatformInterface/ExecuteLlmStepWorker default model. DeepSeek via generic bridge, no deep-seek-platform dependency.

## Task workflow update - 2026-05-17T03:33:30.186Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-05-configured-symfony-generic-platform to origin.
- branch 'task/ai-05-configured-symfony-generic-platform' set up to track 'origin/task/ai-05-configured-symfony-generic-platform'.
- Created PR: https://github.com/ineersa/agent-core/pull/14
- Summary: AI-05 implemented by fork 5zbba9h6tm9z in commit 76a190b0. Added SymfonyAiProviderFactory (108 lines), ConfiguredSymfonyAiPlatformFactory (55 lines), DI wiring for PlatformInterface + defaultModel. DeepSeek via generic bridge, no deep-seek-platform dependency. 7 stale PHPStan baselines removed. AI-09 and AI-10 unblocked.

## Task workflow update - 2026-05-17T21:29:24.339Z
- Recorded fork run: p929smwf7g9q
- Summary: Launched fixup fork p929smwf7g9q for AI-05 PR #14 after AI-15/AI-16 landed. Scope: merge origin/main, resolve conflicts, remove AppConfigResolver/projectCwd/%kernel.project_dir% assumptions, rework provider/platform factories to inject/use AppConfig directly (AppConfig->ai/catalog/cwd), preserve AI-16 services.yaml changes, validate and push branch.

## Task workflow update - 2026-05-17T21:32:20.984Z
- Validation: php bin/console --no-interaction: passed; vendor/bin/phpunit tests/AgentCore/Infrastructure/SymfonyAi/PlatformIntegrationTest.php: passed (2 tests, 15 assertions); castor test: passed (328 tests, 8046 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations); vendor/bin/phpstan analyse -c phpstan.dist.neon: passed (0 errors; baseline 229); castor cs-check: passed; castor cs-fix: clean; main checkout untouched by fork
- Summary: AI-05 PR #14 fixup completed by fork p929smwf7g9q in commit e844e95b, pushed to branch. Merged origin/main (AI-15/AI-16) with only phpstan-baseline conflict, reworked SymfonyAiProviderFactory to inject AppConfig directly, removed AppConfigResolver/projectCwd usage, removed $projectCwd argument from ConfiguredSymfonyAiPlatformFactory and services.yaml PlatformInterface factory, preserved AI-16 service wiring, regenerated phpstan-baseline.neon.

## Task workflow update - 2026-05-17T21:38:29.072Z
- Moved CODE-REVIEW → DONE.
- Merged task/ai-05-configured-symfony-generic-platform into integration checkout.
- Merge made by the 'ort' strategy.
 config/services.yaml                               |  16 ++++
 phpstan-baseline.neon                              |  48 ++--------
 .../ConfiguredSymfonyAiPlatformFactory.php         |  49 ++++++++++
 .../SymfonyAi/SymfonyAiProviderFactory.php         | 104 +++++++++++++++++++++
 4 files changed, 175 insertions(+), 42 deletions(-)
 create mode 100644 src/CodingAgent/Infrastructure/SymfonyAi/ConfiguredSymfonyAiPlatformFactory.php
 create mode 100644 src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #14 merged; Final fixup validation: php bin/console boot passed; PlatformIntegrationTest passed (2 tests, 15 assertions); castor test passed (328 tests, 8046 assertions, 1 pre-existing notice); castor deptrac passed; phpstan passed; cs-check passed
- Summary: PR #14 merged. AI-05 complete: configured Symfony generic providers/platform wired from Hatfield AppConfig, DeepSeek via generic provider, AppConfigResolver/projectCwd assumptions removed during fixup, AI-16 service wiring preserved.
