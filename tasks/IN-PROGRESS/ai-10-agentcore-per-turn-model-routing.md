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
Status: IN-PROGRESS
Branch: task/ai-10-agentcore-per-turn-model-routing
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-10-agentcore-per-turn-model-routing
Fork run: yvvavqya5kov
PR URL:
PR Status:
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
