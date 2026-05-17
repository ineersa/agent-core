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
Status: IN-PROGRESS
Branch: task/ai-05-configured-symfony-generic-platform
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform
Fork run:
PR URL:
PR Status:
Started: 2026-05-17T03:14:13.694Z
Completed:

## Work log
- Created: 2026-05-16T22:01:55.475Z

## Task workflow update - 2026-05-17T03:14:13.694Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-05-configured-symfony-generic-platform.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-05-configured-symfony-generic-platform.
