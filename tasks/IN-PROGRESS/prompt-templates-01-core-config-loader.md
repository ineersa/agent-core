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
Fork run: dxxpp2hl1jgb
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
