# AI-01 Add AI settings shape, defaults, and docs

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-01--add-ai-settings-shape-defaults-and-docs

Goal: introduce the user-facing `ai` config section without changing runtime behavior.

Depends on: Symfony AI 0.9 upgrade merged.

Parallelism: first task; unblocks AI-02, AI-03, AI-08.

Scope:
- Update `config/hatfield.defaults.yaml` with commented/default `ai` shape.
- Update committed `.hatfield/settings.yaml` example comments.
- Update `docs/settings.md` with home/project precedence and examples.
- Document providers: `deepseek`, `llama_cpp`, `zai`.
- Document that every selectable model must be explicitly listed.
- Include seed models: `deepseek/deepseek-v4-pro`, `deepseek/deepseek-v4-flash`, `llama_cpp/flash`, `zai/glm-5.1`, `zai/glm-5v-turbo`.
- Include z.ai compat notes: no developer role, no reasoning effort, `thinking_format: zai`, `zai_tool_stream` on supported models.

## Acceptance criteria
- Existing settings still load when `ai` is absent.
- Docs and examples use Hatfield/YAML snake_case keys.
- No provider construction or model selection behavior changes yet.
- Suggested validation: `castor test`.

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
