# AI-04 Project Hatfield model catalog into Symfony model catalogs

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-04--project-hatfield-model-catalog-into-symfony-model-catalogs

Goal: create thin Symfony model catalogs from Hatfield metadata for Platform routing/capability checks.

Depends on: AI-02.

Parallelism: can run alongside AI-06 and most of AI-07 after AI-02; unblocks AI-05.

Scope:
- Implement `ProjectedSymfonyModelCatalog` or equivalent.
- For each configured model, project to Symfony `Generic::class` with capabilities: messages input, text output, streaming output, tool calling when `tool_calling: true`, thinking when `reasoning: true`.
- Do not carry cost/context/favorites into Symfony catalog classes.
- Unknown models must not be supported.

## Acceptance criteria
- Projected catalog supports only listed models.
- Capabilities reflect Hatfield metadata.
- No use of Symfony built-in DeepSeek/z.ai catalogs as source of truth.
- Suggested validation: `castor test --filter SymfonyModelCatalog`.

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
