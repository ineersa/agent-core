# AI-09 Apply compat-aware message/options shaping before provider invocation

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-09--apply-compat-aware-messageoptions-shaping-before-provider-invocation

Goal: ensure generic providers receive provider-specific request shapes without scattering compat checks.

Depends on: AI-06; provider path from AI-05 is recommended before final integration.

Parallelism: can run after AI-06; joins with AI-05/AI-10 before full replay validation.

Scope:
- Integrate `ReasoningOptionsResolver` into existing pre-provider hook/subscriber path.
- Add focused mapper for provider/model compat quirks.
- z.ai behavior: no OpenAI `developer` role if `supports_developer_role: false`; send `enable_thinking` for non-off reasoning; do not send `reasoning_effort`; keep tool-call streaming expectation documented by `zai_tool_stream`.
- Keep response parsing in existing Symfony AI 0.9 adapter/converter unless real provider traces prove a gap.

## Acceptance criteria
- Invocation options for `zai/glm-5.1` include `enable_thinking` when reasoning is non-off.
- Invocation options for z.ai never include `reasoning_effort`.
- Message conversion does not emit unsupported developer role for providers that disable it.
- Suggested validation: `castor test --filter Compat`; `castor test --filter PlatformIntegration`.

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
