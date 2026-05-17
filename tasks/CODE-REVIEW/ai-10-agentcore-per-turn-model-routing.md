# AI-10 Route per-turn model/reasoning through AgentCore

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-10--route-per-turn-modelreasoning-through-agentcore

Goal: replace hardcoded model behavior with per-turn resolved model and provider routing.

Depends on: AI-07, AI-08; AI-05 provider registry/platform for final explicit provider routing.

Parallelism: preparation can overlap with AI-05; completion unblocks AI-11, AI-12, AI-13.

Scope:
- Extend `ResolvedModel` with provider ID.
- Implement production `ModelResolverInterface` backed by selection services and run/session metadata.
- Update `ExecuteLlmStepWorker`/`ModelInvocationRequest` flow to use resolved model/options rather than only hardcoded default.
- Update `ModelResolverRoutingSubscriber` to call `ModelRoutingEvent::setProvider()` using provider registry when provider ID is present.
- Ensure `RunMetadata.model` is populated and used.

## Acceptance criteria
- Each LLM turn invokes Symfony Platform with raw model name and explicit provider selection.
- New session, resumed session, and explicit CLI model follow documented priority order.
- Existing tests around LLM execution continue to pass.
- Suggested validation: `castor test --filter ExecuteLlmStepWorker`; `castor test --filter ModelResolver`; `castor deptrac`.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/ai-10-agentcore-per-turn-model-routing
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-10-agentcore-per-turn-model-routing
Fork run: ywzesdh62wta
PR URL: https://github.com/ineersa/agent-core/pull/18
PR Status: open
Started: 2026-05-17T21:42:59.913Z
Completed:

## Work log
- Created: 2026-05-16T22:02:34.212Z

## Task workflow update - 2026-05-17T21:42:59.913Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-10-agentcore-per-turn-model-routing.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-10-agentcore-per-turn-model-routing.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-10-agentcore-per-turn-model-routing.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-10-agentcore-per-turn-model-routing.

## Task workflow update - 2026-05-17T21:43:36.747Z
- Recorded fork run: yvvavqya5kov
- Summary: Started AI-10 implementation in background fork yvvavqya5kov. Scope: route per-turn model/reasoning through AgentCore after AI-05/07/08/09/15/16, extend ResolvedModel with provider ID, resolve selected model/reasoning from ModelSelectionService/run/session metadata, pass resolved model/options/_hatfield_reasoning to platform invocation, update ModelResolverRoutingSubscriber to set explicit provider via provider registry/factory, populate/use RunMetadata.model, preserve current AppConfig/DI changes and avoid AppConfigResolver/projectCwd regressions.

## Task workflow update - 2026-05-17T21:51:13.387Z
- Validation: php bin/console --no-interaction: passed; SessionAwareModelResolverTest: passed (6 tests, 15 assertions); SymfonyAiProviderRegistryTest: passed (4 tests, 6 assertions); castor test: passed (349 tests, 8096 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations, 329 allowed); vendor/bin/phpstan: passed (0 errors; baseline 225); castor cs-fix + castor cs-check: clean; main checkout unchanged except pre-existing untracked .pi/plans/runtime-transcript-vertical-slice-plan.md
- Summary: AI-10 implemented by fork yvvavqya5kov in commit da767fe7 on branch task/ai-10-agentcore-per-turn-model-routing. Added per-turn model/reasoning routing: ResolvedModel now carries providerId and reasoning, SessionAwareModelResolver uses ModelSelectionService/session metadata, ModelResolverRoutingSubscriber injects ProviderRegistryInterface and sets _hatfield_reasoning plus explicit provider on ModelRoutingEvent, SymfonyAiProviderRegistry lazily maps provider IDs to Symfony providers, services.yaml wires resolver/registry aliases.

## Task workflow update - 2026-05-17T21:51:25.018Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-10-agentcore-per-turn-model-routing to origin.
- branch 'task/ai-10-agentcore-per-turn-model-routing' set up to track 'origin/task/ai-10-agentcore-per-turn-model-routing'.
- Created PR: https://github.com/ineersa/agent-core/pull/18
- Validation: php bin/console --no-interaction: passed; SessionAwareModelResolverTest: passed (6 tests, 15 assertions); SymfonyAiProviderRegistryTest: passed (4 tests, 6 assertions); castor test: passed (349 tests, 8096 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations, 329 allowed); vendor/bin/phpstan: passed (0 errors; baseline 225); castor cs-fix + castor cs-check: clean
- Summary: AI-10 ready for review. Implemented per-turn model/reasoning routing through AgentCore in commit da767fe7: SessionAwareModelResolver resolves from ModelSelectionService/session metadata; ResolvedModel carries providerId/reasoning; ModelResolverRoutingSubscriber sets _hatfield_reasoning and explicit provider via ProviderRegistryInterface/SymfonyAiProviderRegistry; services.yaml wires resolver and registry aliases.

## Task workflow update - 2026-05-17T22:01:21.373Z
- Recorded fork run: ywzesdh62wta
- Summary: Launched PR #18 review-fix fork ywzesdh62wta. Review comments to address: Tool namespace is overloaded by model/provider-routing classes (ResolvedModel and ProviderRegistryInterface comments), and ModelResolverRoutingSubscriber duplicates the `_hatfield_reasoning` constant already present in CompatRequestShaper. Planned fix: move model/platform/provider-request DTOs from AgentCore\Domain\Tool to AgentCore\Domain\Model, move model/platform contracts from AgentCore\Contract\Tool to AgentCore\Contract\Model while leaving actual Tool types in Tool namespaces, add shared AgentCore provider option-key constants and update ModelResolverRoutingSubscriber + CompatRequestShaper to use them without creating AgentCore→CodingAgent dependency.

## Task workflow update - 2026-05-17T22:09:07.905Z
- Validation: composer dump-autoload: passed; php bin/console --no-interaction: passed; castor test: passed (349 tests, 8096 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations, 329 allowed); vendor/bin/phpstan analyse: passed (0 errors; baseline 225); castor cs-fix + castor cs-check: clean; castor check: quality ok; main checkout untouched except pre-existing untracked .pi/plans/runtime-transcript-vertical-slice-plan.md
- Summary: PR #18 review comments addressed by fork ywzesdh62wta in commit 7a69a94d, pushed to branch. Moved model/platform/provider-request DTOs out of AgentCore\Domain\Tool into AgentCore\Domain\Model (ModelInvocationInput/Options/Request, ModelResolutionOptions, PlatformInvocationResult, ProviderRequest, ResolvedModel). Moved model/platform contracts out of AgentCore\Contract\Tool into AgentCore\Contract\Model (ModelResolverInterface, PlatformInterface, ProviderRegistryInterface). Added ProviderRequestOptionKeys shared constants and updated ModelResolverRoutingSubscriber + CompatRequestShaper to use them, eliminating duplicated _hatfield_reasoning literal without AgentCore depending on CodingAgent. Updated use statements/services/tests and regenerated PHPStan baseline.
