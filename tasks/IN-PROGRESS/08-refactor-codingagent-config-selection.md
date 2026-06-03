# 08-refactor-codingagent-config-selection: split model selection and config path resolution

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/coding-agent-architecture.md

Separate pure model/reasoning resolution from settings persistence and simplify repetitive config path resolution.

Scope:
- Extract read-side ModelResolver for explicit/session/default/fallback model and reasoning selection.
- Extract or clarify write-side ModelSettingsPersister for home settings and session metadata writes.
- Keep ModelSelectionService as a compatibility/coordinator facade for existing callers during this internal refactor.
- Replace AppConfigLoader hardcoded path if-blocks with a declarative path map.

## Acceptance criteria
- Pure model/reasoning resolution can be tested without filesystem or session metadata persistence dependencies.
- Model mutation/persistence tests are isolated from catalog resolution tests.
- AppConfigLoader path resolution is declarative and covered by tests for existing path-bearing config keys.
- Run and report Castor validation: castor test --filter=ModelSelectionService/AppConfigLoader relevant tests plus castor check, or exact environmental blockers.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/08-refactor-codingagent-config-selection
Worktree: /home/ineersa/projects/agent-core-worktrees/08-refactor-codingagent-config-selection
Fork run: scout-cleanup-services-yaml
PR URL: https://github.com/ineersa/agent-core/pull/90
PR Status: open
Started: 2026-06-03T20:50:07.554Z
Completed:

## Work log
- Created: 2026-06-03T00:32:17.712Z

## Task workflow update - 2026-06-03T20:42:28.906Z
- Summary: Finalized plan with user decisions: (1) SessionMetadataStore stays in ModelResolver as-is, (2) favorites stay in ModelSelectionService, (3) ModelSelectionService validates before delegating to persister, (4) flat dot-notation path map with Symfony PropertyAccess for AppConfigLoader, (5) test migration with simplification — collapse resolution tests into data providers, reduce from 56 tests to ~20 focused tests.

## Task workflow update - 2026-06-03T20:46:53.090Z
- Summary: Implementation plan finalized. Architecture: ModelResolver (read-only, ~120 lines, deps: AppConfig + SessionMetadataStore), ModelSettingsPersister (write-only, ~60 lines, deps: HomeSettingsWriter + SessionMetadataStore, trusts input — no validation), ModelSelectionService (coordinator, ~150 lines, owns favorites + favRaw cache + validation before delegating). AppConfigLoader path map: flat constant using Symfony PropertyAccess bracket notation ([tui][theme_paths] => 'list', etc.), 5 if-blocks collapse into single loop. Tests: ~25 focused tests across ModelResolverTest (pure unit, ~10 tests with data providers), ModelSettingsPersisterTest (~4 tests), ModelSelectionServiceTest (~8 coordinator-level), AppConfigLoaderTest (add path-map coverage). No external contract changes.

## Task workflow update - 2026-06-03T20:50:07.554Z
- Moved TODO → IN-PROGRESS.
- Created branch task/08-refactor-codingagent-config-selection.
- Created worktree /home/ineersa/projects/agent-core-worktrees/08-refactor-codingagent-config-selection.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/08-refactor-codingagent-config-selection.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/08-refactor-codingagent-config-selection.

## Task workflow update - 2026-06-03T20:53:03.323Z
- Recorded fork run: o63kpl9vykso
- Summary: Fork launched (o63kpl9vykso) to implement task 08. Scope: create ModelResolver (read-only) and ModelSettingsPersister (write-only), rewrite ModelSelectionService as coordinator, replace AppConfigLoader resolveConfigPaths() with declarative path map using Symfony PropertyAccess, create ModelResolverTest (pure unit), simplify ModelSelectionServiceTest (8 tests), update 4 other test files, update services.yaml.

## Task workflow update - 2026-06-03T21:00:01.226Z
- Recorded fork run: o63kpl9vykso
- Validation: castor test — 1572 tests, 4694 assertions, 0 failures; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: Fork o63kpl9vykso completed task 08. Commit 60665817 on task/08-refactor-codingagent-config-selection. 13 files changed (+831/-971): created ModelResolver (235 lines, read-only), ModelSettingsPersister (63 lines, write-only), rewrote ModelSelectionService (243 lines, was 360, coordinator), replaced 5 hardcoded if-blocks in AppConfigLoader with PATH_CONFIG constant + PropertyAccess, created ModelResolverTest (26 pure unit tests), simplified ModelSelectionServiceTest (14 tests, was 56), updated 6 test files, updated services.yaml. 1572 tests pass, 0 deptrac violations, 0 phpstan errors, cs-check clean. Test count dropped from 1594 to 1572 (expected — resolution tests moved from kernel-boot integration to pure unit).

## Task workflow update - 2026-06-03T21:19:38.905Z
- Validation: castor test — 1588 tests, 4732 assertions, 0 failures; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean; Re-review verdict: APPROVED
- Summary: Review fix fork 4x9nsfqz50m9 completed. Amended commit to afdd0c8f. Resolved all 8 review findings: restored 7 session metadata tests, z.ai reasoning test, ModelSettingsPersisterTest (4 tests), provider thinking_levels tests, favorites-filter test, rationale comments, z.ai docblock. 1588 tests pass (+16), 0 deptrac/phpstan/cs issues. Re-review: APPROVED (no critical/regular issues; convention note on persister docblock, simplify note on newInstanceWithoutConstructor).
Castor Check Status: passed
Castor Check Commit: afdd0c8f6bda0fa36cf64a0209204aab5f6a4dab
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 600s
Castor Check Completed: 2026-06-03T21:24:39.564Z
Castor Check Output SHA256: e4f2d9cf8324acd44b1aa2da3b829fad8800939f2276457479d47402afbb2ee8

## Task workflow update - 2026-06-03T21:24:43.242Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (600s timeout). Commit: afdd0c8f6bda.
- Pushed task/08-refactor-codingagent-config-selection to origin.
- branch 'task/08-refactor-codingagent-config-selection' set up to track 'origin/task/08-refactor-codingagent-config-selection'.
- Created PR: https://github.com/ineersa/agent-core/pull/90

## Task workflow update - 2026-06-03T21:29:43.726Z
- Moved CODE-REVIEW → IN-PROGRESS.

## Task workflow update - 2026-06-03T21:31:15.627Z
- Recorded fork run: kejg2rqdmwcj
- Validation: castor test — 1588/4732 pass; castor phpstan — 0 errors; castor cs-check — clean
- Summary: Review fix fork kejg2rqdmwcj: removed redundant ModelResolver: ~ and ModelSettingsPersister: ~ from config/services.yaml per PR #90 inline feedback. Autowiring handles both classes via resource binding. Commit amended to 3eada3a7, now 13 files changed (+1152/-905). 1588 tests pass, 0 phpstan errors, cs-check clean.

## Task workflow update - 2026-06-03T21:38:06.600Z
- Recorded fork run: rz1ztwxqcsba
- Validation: castor test — 1588/4732 pass; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: Review fix fork rz1ztwxqcsba: removed 16 of 17 redundant ~ service definitions from config/services.yaml per PR #90 reviewer feedback. Only StringTemplateRenderer: ~ preserved (vendor class outside src/ resource paths). Commit amended to 718c068f, 14 files changed (+1152/-937). 1588 tests pass, 0 deptrac/phpstan/cs issues.

## Task workflow update - 2026-06-03T21:47:48.960Z
- Recorded fork run: scout-cleanup-services-yaml
- Validation: castor test — 1588/4732 pass; castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: config/services.yaml cleanup: removed extra blank lines (parameters→services), dead ExtensionApiBridge backward-compat definition, misplaced extension comment above LoggingConfig, orphan Image gating comment block, stale ToolExecutionPolicyResolver comment. Commit amended to 8fbb31cd. services.yaml diff now -51 lines (was -32). Only 1 ~ remains (StringTemplateRenderer — vendor class). 1588 tests pass, 0 deptrac/phpstan/cs issues.
