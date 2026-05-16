# AI-06 Implement reasoning and compat option resolver

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-06--implement-reasoning-and-compat-option-resolver

Goal: convert global reasoning level + model metadata into provider invocation options.

Depends on: AI-02.

Parallelism: can run alongside AI-04 and most of AI-07 after AI-02; unblocks AI-09.

Scope:
- Implement `ReasoningOptionsResolver`.
- Inputs: `AiModelRef`, user-facing level `off|minimal|low|medium|high|xhigh`.
- Return `[]` for `off`, non-reasoning models, missing map, or null map value.
- Use `thinking_level_map` for model-specific translation.
- For `compat.thinking_format: zai`, emit `enable_thinking: true` for mapped non-off levels.
- For `supports_reasoning_effort: false`, never emit `reasoning_effort`.
- Leave room for future OpenAI-style mappings but do not invent unsupported semantics.

## Acceptance criteria
- z.ai maps every non-off configured level to `enable_thinking: true`.
- llama.cpp `flash` produces no reasoning options.
- Unit tests prove `reasoning_effort` is omitted when unsupported.
- Suggested validation: `castor test --filter Reasoning`.

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
