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
Status: CODE-REVIEW
Branch: task/ai-06-reasoning-compatibility-option-resolver
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-06-reasoning-compatibility-option-resolver
Fork run: bqx25cppiqnc
PR URL: https://github.com/ineersa/agent-core/pull/12
PR Status: open
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

## Task workflow update - 2026-05-17T00:49:01.042Z
- Recorded fork run: tmelwbm0esxv
- Summary: Launched fork tmelwbm0esxv to implement AI-06 in worktree /home/ineersa/projects/agent-core-worktrees/ai-06-reasoning-compatibility-option-resolver. Scope: add reasoning/compatibility option resolver using HatfieldModelCatalog and AiModelReference; map off/non-reasoning/missing/null to []; z.ai thinking_format emits enable_thinking true; supports_reasoning_effort false never emits reasoning_effort; add focused tests; run castor check; commit and push.

## Task workflow update - 2026-05-17T01:13:47.002Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-06-reasoning-compatibility-option-resolver to origin.
- branch 'task/ai-06-reasoning-compatibility-option-resolver' set up to track 'origin/task/ai-06-reasoning-compatibility-option-resolver'.
- Created PR: https://github.com/ineersa/agent-core/pull/12
- Summary: AI-06 implemented by fork tmelwbm0esxv in commit 34ebc87c. Added ReasoningOptionsResolver (92 lines) converting (AiModelReference, user-facing level) → provider invocation options. Handles all termination branches: off, unknown level, non-reasoning model, empty/null map value, missing model, disabled provider. Supports z.ai enable_thinking emission via thinking_format: zai, OpenAI-style reasoning_effort for standard providers, supports_reasoning_effort: false suppression, model-level thinking_format override of provider-level, case-insensitive level input. 16 focused tests covering all acceptance criteria. Cleaned up 6 stale PHPStan baseline entries for properties now read by the resolver.

## Task workflow update - 2026-05-17T01:21:52.242Z
- Recorded fork run: bqx25cppiqnc
- Summary: Launched fork bqx25cppiqnc to resolve PR #12 phpstan-baseline.neon merge conflict against main (which now includes AI-04's merged baseline removals). Scope: merge origin/main, resolve baseline by keeping net result of both removals plus AI-06 new entry, handle stale task files, run castor check, push, verify mergeable.
