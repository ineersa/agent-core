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
Status: CODE-REVIEW
Branch: task/ai-04-symfony-model-catalog-projector
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-04-symfony-model-catalog-projector
Fork run: y12whk8adi1z
PR URL: https://github.com/ineersa/agent-core/pull/11
PR Status: open
Started: 2026-05-17T00:47:42.154Z
Completed:

## Work log
- Created: 2026-05-16T22:01:55.475Z

## Task workflow update - 2026-05-17T00:47:42.154Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-04-symfony-model-catalog-projector.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-04-symfony-model-catalog-projector.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-04-symfony-model-catalog-projector.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-04-symfony-model-catalog-projector.

## Task workflow update - 2026-05-17T00:48:56.565Z
- Recorded fork run: y12whk8adi1z
- Summary: Launched fork y12whk8adi1z to implement AI-04 in worktree /home/ineersa/projects/agent-core-worktrees/ai-04-symfony-model-catalog-projector. Scope: add ProjectedSymfonyModelCatalog/equivalent adapting Hatfield AiModelDefinition metadata to Symfony Generic model catalog capabilities; unknown models unsupported; no provider/platform construction; add focused tests; run castor check; commit and push.

## Task workflow update - 2026-05-17T01:03:24.939Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-04-symfony-model-catalog-projector to origin.
- branch 'task/ai-04-symfony-model-catalog-projector' set up to track 'origin/task/ai-04-symfony-model-catalog-projector'.
- Created PR: https://github.com/ineersa/agent-core/pull/11
- Validation: Fork validation: `vendor/bin/phpunit --filter ProjectedSymfonyModelCatalogTest` passed: 14 tests, 55 assertions.; Fork validation: `castor deptrac` passed: 0 violations, 77 uncovered, 325 allowed.; Fork validation: `castor test` passed: 301 tests, 7993 assertions, 1 PHPUnit notice.; Fork validation: `vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress` passed: 0 errors.; Fork validation: `castor cs-fix && castor cs-check` clean.; Fork validation: full `castor check` passed.; Parent verification: worktree branch clean at commit 48cdaacd.
- Summary: AI-04 implemented by fork y12whk8adi1z in commit 48cdaacd. Added ProjectedSymfonyModelCatalog (66 lines) that adapts array<string, AiModelDefinition> to Symfony AI AbstractModelCatalog entries using Symfony\AI\Platform\Bridge\Generic\CompletionsModel and capabilities derived from Hatfield metadata. Unknown models are rejected via inherited ModelNotFoundException behavior. Added focused ProjectedSymfonyModelCatalogTest coverage. Added symfony/ai-generic-platform:^0.9 dependency needed for CompletionsModel, deptrac layers/rules for CodingAgent SymfonyAi infrastructure and Symfony AI Platform, and removed stale PHPStan baselines for AiModelDefinition::$reasoning and $toolCalling now that they are read.
