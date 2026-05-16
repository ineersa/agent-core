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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-16T22:02:34.212Z
