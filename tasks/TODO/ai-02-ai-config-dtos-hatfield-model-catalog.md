# AI-02 Implement AI config DTOs and Hatfield model catalog

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-02--implement-ai-config-dtos-and-hatfield-model-catalog

Goal: parse `ai` settings into typed structures and expose the authoritative model catalog.

Depends on: AI-01.

Parallelism: can run alongside AI-03 and AI-08 after AI-01; unblocks AI-04, AI-06, AI-07.

Scope:
- Add DTOs under `src/CodingAgent/Config/Ai/` or equivalent: `AiConfig`, `AiProviderConfig`, `AiModelDefinition`, `AiCost`, `AiCompat`, `AiModelRef`.
- Extend `AppConfig::fromArray()` to parse `ai` while preserving unknown/raw settings.
- Implement `HatfieldModelCatalog` with provider/model lookup, `requireModel`, `allModels`, and `isAvailable` for enabled/listed models only.
- Explicit-only behavior: unknown model names are rejected for every provider, including llama.cpp.

## Acceptance criteria
- Rich model metadata parses: context window, max tokens, input modalities, tool-calling, reasoning, thinking map, cost, compat.
- `provider/model` parsing rejects malformed values and unknown providers/models.
- llama.cpp only exposes listed models such as `llama_cpp/flash`.
- Suggested validation: `castor test --filter Ai`; `castor phpstan`.

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
