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
Status: CODE-REVIEW
Branch: task/ai-02-ai-config-dtos-hatfield-model-catalog
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-02-ai-config-dtos-hatfield-model-catalog
Fork run: bxnoltgwdx16
PR URL: https://github.com/ineersa/agent-core/pull/9
PR Status: open
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

## Task workflow update - 2026-05-16T22:32:11.164Z
- Recorded fork run: 4s33fnreimt8
- Summary: Launched implementation fork 4s33fnreimt8 in worktree /home/ineersa/projects/agent-core-worktrees/ai-02-ai-config-dtos-hatfield-model-catalog.

## Task workflow update - 2026-05-16T22:46:28.739Z
- Validation: AI-02 worktree: focused new tests passed: AiModelRefTest 10 tests/22 assertions; AiConfigTest 14 tests/81 assertions; HatfieldModelCatalogTest 18 tests/45 assertions.; AI-02 worktree: existing config/session tests passed: 47 tests, 115 assertions.; AI-02 worktree: `castor test` passed: 276 tests, 7921 assertions, 1 PHPUnit notice.; AI-02 worktree: `castor deptrac` passed: 0 violations, 66 uncovered, 323 allowed.; AI-02 worktree: initial `castor phpstan` reported 35 expected dead-code findings for new catalog/DTO APIs that downstream tasks will consume; added temporary baselines in phpstan-baseline.neon.; AI-02 worktree: `castor phpstan` passed after baseline update.; AI-02 worktree: `castor cs-fix` applied formatting to new AI config classes.; AI-02 worktree: full `castor check` passed.
- Summary: AI-02 implementation complete. Worktree has commits ea8410dc (AI config DTOs/catalog implementation) and 31813e32 (CS-fixer formatting plus temporary PHPStan baselines for DTO/catalog APIs until downstream AI-04/AI-07/AI-10 consumers land). Added typed AI config DTOs, AppConfig ai parsing, and HatfieldModelCatalog with explicit-only model lookup.

## Task workflow update - 2026-05-16T22:46:50.877Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-02-ai-config-dtos-hatfield-model-catalog to origin.
- branch 'task/ai-02-ai-config-dtos-hatfield-model-catalog' set up to track 'origin/task/ai-02-ai-config-dtos-hatfield-model-catalog'.
- Created PR: https://github.com/ineersa/agent-core/pull/9
- Validation: Focused AI config/catalog tests passed: AiModelRefTest 10 tests/22 assertions; AiConfigTest 14 tests/81 assertions; HatfieldModelCatalogTest 18 tests/45 assertions.; Existing config/session tests passed: 47 tests, 115 assertions.; `castor test` passed: 276 tests, 7921 assertions, 1 PHPUnit notice.; `castor deptrac` passed: 0 violations.; `castor phpstan` passed after temporary baselines for AI catalog DTO/API members that downstream tasks will consume.; `castor cs-fix` applied formatting; full `castor check` passed.
- Summary: AI-02 ready for review. Added typed AI config DTOs, AppConfig ai parsing, and HatfieldModelCatalog with explicit-only model lookup. Branch includes implementation commit ea8410dc and cleanup/baseline commit 31813e32.

## Task workflow update - 2026-05-16T23:06:06.338Z
- Recorded fork run: bxnoltgwdx16
- Summary: Launched fork bxnoltgwdx16 to address PR #9 comments: rename AiCompat/compat terminology to AiCompatibility/compatibility, rename AiModelRef to AiModelReference, set AiCompatibility supportsDeveloperRole=false and supportsReasoningEffort=true defaults, update plan/task/docs references, run castor check, commit and push branch updates.
