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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-16T22:01:55.475Z
