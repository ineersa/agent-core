# AI-06 Implement reasoning and compatibility option resolver

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-06--implement-reasoning-and-compatibility-option-resolver

Goal: convert global reasoning level + model metadata into provider invocation options.

Depends on: AI-02.

Parallelism: can run alongside AI-04 and most of AI-07 after AI-02; unblocks AI-09.

Scope:
- Implement `ReasoningOptionsResolver`.
- Inputs: `AiModelReference`, user-facing level `off|minimal|low|medium|high|xhigh`.
- Return `[]` for `off`, non-reasoning models, missing map, or null map value.
- Use `thinking_level_map` for model-specific translation.
- For `compatibility.thinking_format: zai`, emit `enable_thinking: true` for mapped non-off levels.
- For `supports_reasoning_effort: false`, never emit `reasoning_effort`.
- Leave room for future OpenAI-style mappings but do not invent unsupported semantics.

## Acceptance criteria
- z.ai maps every non-off configured level to `enable_thinking: true`.
- llama.cpp `flash` produces no reasoning options.
- Unit tests prove `reasoning_effort` is omitted when unsupported.
- Suggested validation: `castor test --filter Reasoning`.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/ai-06-reasoning-compatibility-option-resolver
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-06-reasoning-compatibility-option-resolver
Fork run:
PR URL:
PR Status:
Started: 2026-05-17T00:47:51.323Z
Completed:

## Work log
- Created: 2026-05-16T22:01:55.475Z

## Task workflow update - 2026-05-17T00:47:51.323Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-06-reasoning-compatibility-option-resolver.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-06-reasoning-compatibility-option-resolver.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-06-reasoning-compatibility-option-resolver.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-06-reasoning-compatibility-option-resolver.
