# PT-01 Prompt template core config, parser, loader, and catalog

## Goal
Reference plan: `.pi/plans/prompt-templates-implementation-plan.md`.

Scope:
- Implement the prompt-template foundation without wiring it into runtime/TUI behavior yet.
- Add top-level Hatfield `prompts: []` settings support and path resolution.
- Add AppPromptTemplate layer/classes for parsing args, substituting placeholders, parsing frontmatter, loading templates, diagnostics, load result, runtime CLI override config, and cached `PromptTemplateService`.
- Add Runtime/Contract catalog DTO/interface needed by later TUI work; do not add a `PromptTemplateExpanderInterface`.
- Canonicalize template names to lowercase using filename stem (`strtolower`/equivalent); `Review.md` and `review.md` collide as `review`.
- Ignore unknown frontmatter keys, including Pi's `argument-hint`, for MVP.

Dependencies: none.

Enables parallel follow-up: PT-02 and PT-03 can start after this lands.

## Acceptance criteria
- `config/hatfield.defaults.yaml`, `.hatfield/settings.yaml` if applicable, `docs/settings.md` references if touched, and `AppConfig/AppConfigLoader` support top-level `prompts: []` only; no `prompts.paths` or `prompts.enabled`.
- Prompt template parser/substitutor/frontmatter/loader/service classes exist under `src/CodingAgent/PromptTemplate/` with diagnostics for read/YAML/collision local degradation and no raw prompt/content in logs.
- `PromptTemplateCatalogInterface` and `PromptTemplateCommand` (name, description only) exist under `src/CodingAgent/Runtime/Contract/` for TUI-safe catalog access.
- Loader scans `~/.hatfield/prompts/*.md`, `<cwd>/.hatfield/prompts/*.md`, settings `prompts: []`, and CLI runtime paths non-recursively; first lowercase name wins; duplicates produce diagnostics.
- Unit tests cover argument parsing, substitution, frontmatter parsing, loader order/collisions/lowercase canonicalization, ignored unknown frontmatter keys, config path resolution, and service caching/expansion behavior.
- `castor test`, `castor deptrac`, `castor phpstan`, and `castor cs-check` pass for the focused implementation.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/prompt-templates-01-core-config-loader
Worktree: /home/ineersa/projects/agent-core-worktrees/prompt-templates-01-core-config-loader
Fork run: lrh416qv4ipw
PR URL:
PR Status:
Started: 2026-06-12T01:42:36.569Z
Completed:

## Work log
- Created: 2026-06-09T00:09:50.965Z

## Task workflow update - 2026-06-12T01:42:36.569Z
- Moved TODO → IN-PROGRESS.
- Created branch task/prompt-templates-01-core-config-loader.
- Created worktree /home/ineersa/projects/agent-core-worktrees/prompt-templates-01-core-config-loader.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/prompt-templates-01-core-config-loader.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/prompt-templates-01-core-config-loader.

## Task workflow update - 2026-06-12T01:47:03.503Z
- Orchestrator claimed task and created worktree `/home/ineersa/projects/agent-core-worktrees/prompt-templates-01-core-config-loader` on branch `task/prompt-templates-01-core-config-loader`.
- Loaded task-workflow/testing/subagents guidance; read `.pi/plans/prompt-templates-implementation-plan.md` sections for PT-01 scope and validation.
- Scout findings: add `PromptsConfig` through `AppConfig::fromContainer()` and `AppConfigLoader::PATH_CONFIG['[prompts]']='list'`; use `SettingsPathResolver` for path-bearing settings; add `AppPromptTemplate` deptrac layer and allow AppConfig/AppRuntimeContract/SymfonyYaml (plus logger if needed); add `PromptTemplateCatalogInterface`/`PromptTemplateCommand` under Runtime/Contract so later TUI work stays deptrac-safe.
- Scout findings: tests should use `TestDirectoryIsolation` and shared `TestLogger`; QA commands must use Castor; PT-01 does not wire runtime/TUI behavior yet, so no TmuxHarness E2E is required for this task.

## Task workflow update - 2026-06-12T01:48:11.490Z
- Recorded fork run: dxxpp2hl1jgb
- Launched implementation fork `dxxpp2hl1jgb` in worktree `/home/ineersa/projects/agent-core-worktrees/prompt-templates-01-core-config-loader` with explicit PT-01 scope, boundaries, files, tests, Castor validation commands, and commit/report requirements.

## Task workflow update - 2026-06-12T02:01:56.265Z
- Recorded fork run: dxxpp2hl1jgb
- Validation: Fork reported: `castor test --filter=PromptTemplateArgumentParserTest` OK (16 tests, 0 failures).; Fork reported: `castor test --filter=PromptTemplateSubstitutorTest` OK (23 tests, 0 failures).; Fork reported: `castor test --filter=PromptTemplateFrontmatterParserTest` OK (11 tests, 0 failures).; Fork reported: `castor test --filter=PromptTemplateLoaderTest` OK (20 tests, 0 failures).; Fork reported: `castor test --filter=PromptTemplateServiceTest` OK (10 tests, 0 failures).; Fork reported: `castor test --filter=PromptsConfigTest` OK (7 tests, 0 failures).; Fork reported: `castor test` OK (2419 tests across 7 suites, 0 failures).; Fork reported: `castor deptrac` OK (0 violations).; Fork reported: `castor phpstan` OK (0 errors).; Fork reported: `castor cs-check` OK (0 fixes needed).; Orchestrator verification: `git status --short`, `git branch --show-current`, `git log -1 --oneline` showed clean worktree on `task/prompt-templates-01-core-config-loader` at `24aedbf7 PT-01: Prompt template core config, parser, loader, and catalog`.; Orchestrator verification: `git diff --stat origin/main...HEAD` showed 25 files changed, 2155 insertions.
- Summary: Implementation fork completed PT-01 foundation and committed `24aedbf7939eae4fa0f81cc5d8880bfb9baa9b14` (`PT-01: Prompt template core config, parser, loader, and catalog`) on branch `task/prompt-templates-01-core-config-loader`. Verified worktree is clean and diff stat is 25 files / 2155 insertions, matching expected scope: config/docs/settings, `src/CodingAgent/PromptTemplate/*`, Runtime/Contract catalog DTO/interface, service wiring, deptrac, and prompt-template/config tests. Confirmed no PT-02/PT-03 runtime/TUI wiring files were changed.

## Task workflow update - 2026-06-12T02:15:47.195Z
- Reviewer subagent returned `APPROVE WITH SUGGESTIONS`: no critical issues; actionable suggestions were to remove the public `PromptTemplateService::loadResult()` test-only API and document/test `${@}` substitution behavior. Launched fix fork `lrh416qv4ipw` in the task worktree with those exact changes and focused Castor validation instructions.

## Task workflow update - 2026-06-12T02:18:43.258Z
- Recorded fork run: lrh416qv4ipw
- Validation: Fork reported: `castor test --filter=PromptTemplateServiceTest` OK (10 tests, 16 assertions, 0 failures).; Fork reported: `castor test --filter=PromptTemplateSubstitutorTest` OK (24 tests, 25 assertions, 0 failures).; Fork reported: `castor phpstan` OK (0 errors).; Fork reported: `castor cs-check` OK (0 fixes).; Orchestrator verification: `git status --short --branch` clean on `task/prompt-templates-01-core-config-loader`.; Orchestrator verification: `git log --oneline --decorate -5` shows HEAD `ce058dd6` followed by implementation commit `24aedbf7`.
- Summary: Review-fix fork completed and committed `ce058dd6` (`PT-01 review: remove loadResult() public API, add ${@} edge-behavior docblock + test`). Changes remove public `PromptTemplateService::loadResult()` and update cache test to use public API only; document/test actual `${@}` passthrough behavior. Worktree verified clean at `ce058dd6`.
