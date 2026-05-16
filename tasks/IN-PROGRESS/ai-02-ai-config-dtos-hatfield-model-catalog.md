# AI-02 Implement AI config DTOs and Hatfield model catalog

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-02--implement-ai-config-dtos-and-hatfield-model-catalog

Goal: parse `ai` settings into typed structures and expose the authoritative model catalog.

Depends on: AI-01.

Parallelism: can run alongside AI-03 and AI-08 after AI-01; unblocks AI-04, AI-06, AI-07.

Scope:
- Add DTOs under `src/CodingAgent/Config/Ai/` or equivalent: `AiConfig`, `AiProviderConfig`, `AiModelDefinition`, `AiCost`, `AiCompatibility`, `AiModelReference`.
- Extend `AppConfig::fromArray()` to parse `ai` while preserving unknown/raw settings.
- Implement `HatfieldModelCatalog` with provider/model lookup, `requireModel`, `allModels`, and `isAvailable` for enabled/listed models only.
- Explicit-only behavior: unknown model names are rejected for every provider, including llama.cpp.

## Acceptance criteria
- Rich model metadata parses: context window, max tokens, input modalities, tool-calling, reasoning, thinking map, cost, compatibility.
- `provider/model` parsing rejects malformed values and unknown providers/models.
- llama.cpp only exposes listed models such as `llama_cpp/flash`.
- Suggested validation: `castor test --filter Ai`; `castor phpstan`.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/ai-02-ai-config-dtos-hatfield-model-catalog
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-02-ai-config-dtos-hatfield-model-catalog
Fork run:
PR URL:
PR Status:
Started: 2026-05-16T22:30:38.613Z
Completed:

## Work log
- Created: 2026-05-16T22:01:55.475Z

## Task workflow update - 2026-05-16T22:30:38.614Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-02-ai-config-dtos-hatfield-model-catalog.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-02-ai-config-dtos-hatfield-model-catalog.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-02-ai-config-dtos-hatfield-model-catalog.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-02-ai-config-dtos-hatfield-model-catalog.
- Summary: Starting Batch B task AI-02 after AI-01 completion: config DTOs and Hatfield model catalog.
