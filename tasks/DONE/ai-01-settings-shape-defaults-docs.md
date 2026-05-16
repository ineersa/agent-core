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
Status: DONE
Branch: task/ai-01-settings-shape-defaults-docs
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-01-settings-shape-defaults-docs
Fork run: vsq3jonjgz0z
PR URL: https://github.com/ineersa/agent-core/pull/7
PR Status: merged
Started: 2026-05-16T22:06:48.406Z
Completed: 2026-05-16T22:28:35.257Z

## Work log
- Created: 2026-05-16T22:01:55.475Z

## Task workflow update - 2026-05-16T22:06:48.406Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-01-settings-shape-defaults-docs.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-01-settings-shape-defaults-docs.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-01-settings-shape-defaults-docs.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-01-settings-shape-defaults-docs.
- Summary: Starting AI-01 settings schema/docs as the first Symfony AI Platform integration task.

## Task workflow update - 2026-05-16T22:07:04.984Z
- Recorded fork run: gta95o1skdll
- Summary: Launched fork gta95o1skdll to implement AI-01 in worktree /home/ineersa/projects/agent-core-worktrees/ai-01-settings-shape-defaults-docs.

## Task workflow update - 2026-05-16T22:13:59.077Z
- Validation: Symfony YAML parse of config/hatfield.defaults.yaml: OK, parsed keys remain tui, sessions (ai absent because commented).; Symfony YAML parse of .hatfield/settings.yaml: OK, parsed keys remain tui (ai absent because commented).; php bin/console --no-interaction: boots and shows help.; vendor/bin/phpunit --filter "Settings|Config|AppConfig": 34 tests, 70 assertions, passed.; castor test full suite could not run due to pre-existing unrelated EventStoreInterface not found issue in worktree/vendor/branch state.
- Summary: AI-01 implementation complete in worktree commit 7e430fc1. Changes are docs/config only: added fully-commented AI settings shape to config/hatfield.defaults.yaml and .hatfield/settings.yaml, expanded docs/settings.md with ai.default_model, ai.default_reasoning, ai.providers, provider/model/compat/reasoning references, model ref format, and full examples. No runtime behavior changed; .hatfield.example/ was not recreated.

## Task workflow update - 2026-05-16T22:14:09.667Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-01-settings-shape-defaults-docs to origin.
- branch 'task/ai-01-settings-shape-defaults-docs' set up to track 'origin/task/ai-01-settings-shape-defaults-docs'.
- Created PR: https://github.com/ineersa/agent-core/pull/7
- Validation: Symfony YAML parse of config/hatfield.defaults.yaml: OK, ai section commented/absent from parsed keys.; Symfony YAML parse of .hatfield/settings.yaml: OK, ai section commented/absent from parsed keys.; php bin/console --no-interaction: boots successfully.; vendor/bin/phpunit --filter "Settings|Config|AppConfig": 34 tests, 70 assertions, passed.; Full castor test not completed due pre-existing unrelated EventStoreInterface not found issue reported by fork.
- Summary: AI-01 ready for review. Worktree branch committed at 7e430fc1 with docs/config-only AI settings schema updates; no runtime behavior changes.

## Task workflow update - 2026-05-16T22:19:52.135Z
- Recorded fork run: vsq3jonjgz0z
- Summary: Launched follow-up fork vsq3jonjgz0z to investigate and fix/reclassify the full `castor test` failure (`EventStoreInterface` not found) on the AI-01 PR branch.

## Task workflow update - 2026-05-16T22:24:19.484Z
- Validation: main: `composer install` updated stale vendor symfony/ai-platform v0.8.1→v0.9.0 and symfony/ai-agent v0.8.0→v0.9.0.; main before composer install: EventStoreInterface autoload check failed because vendor/composer/autoload_static.php pointed at an old worktree path; `composer dump-autoload` fixed autoload path.; main after composer install + test fix: `vendor/bin/phpunit --filter HatfieldSessionStoreTest --colors=always` passed: 14 tests, 60 assertions.; main after composer install + test fix: `castor test` passed: 234 tests, 7773 assertions, 1 PHPUnit notice.; AI-01 worktree: cherry-picked test fix as 1626eb22, ran `composer install`, verified symfony/ai-platform v0.9.0 installed, and `castor test` passed: 234 tests, 7773 assertions, 1 PHPUnit notice.; AI-01 branch pushed: 7e430fc1..1626eb22 to origin/task/ai-01-settings-shape-defaults-docs.
- Summary: Investigated failing full test run. Root cause was local stale vendor in both main and AI-01 worktree: composer.lock required symfony/ai-* v0.9.0, but vendor had v0.8.x, causing Symfony AI 0.9-style AssistantMessage test helpers to call the v0.8 constructor. `composer install` updated vendor to symfony/ai-platform v0.9.0 and symfony/ai-agent v0.9.0. After vendor was fixed, one real stale test remained: HatfieldSessionStoreTest constructed AppConfigResolver with a string instead of AppResourceLocator. Fixed that in commit 501adaac on main and cherry-picked it to PR branch as 1626eb22, then pushed PR #7 branch.

## Task workflow update - 2026-05-16T22:26:47.959Z
- Validation: In AI-01 worktree: Symfony YAML parse of `.hatfield/settings.yaml` succeeds and parsed keys are `tui,ai`; `ai.default_model` resolves to `deepseek/deepseek-v4-pro`.; In AI-01 worktree: `php bin/console --no-interaction` boots successfully.; In AI-01 worktree: `vendor/bin/phpunit --filter "Settings|Config|AppConfig" --colors=always` passed: 34 tests, 70 assertions.; In AI-01 worktree: `castor test` passed: 234 tests, 7773 assertions, 1 PHPUnit notice.
- Summary: Addressed code review feedback: uncommented the `.hatfield/settings.yaml` AI section so this project has active provider/model settings instead of only commented examples. Built-in `config/hatfield.defaults.yaml` remains commented/documentational, but project settings now actively define default model/reasoning and deepseek, llama_cpp, and zai providers. Pushed PR branch update commit 0d377784.

## Task workflow update - 2026-05-16T22:28:35.257Z
- Moved CODE-REVIEW → DONE.
- Merged task/ai-01-settings-shape-defaults-docs into integration checkout.
- Merge made by the 'ort' strategy.
 .hatfield/settings.yaml       | 114 +++++++++++++++++
 config/hatfield.defaults.yaml | 105 ++++++++++++++++
 docs/settings.md              | 284 ++++++++++++++++++++++++++++++++++++++++++
 3 files changed, 503 insertions(+)
- Removed worktree /home/ineersa/projects/agent-core-worktrees/ai-01-settings-shape-defaults-docs.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #7 merged on GitHub.; PR branch validation after review update: `castor test` passed: 234 tests, 7773 assertions, 1 PHPUnit notice.
- Summary: PR #7 merged. AI-01 complete: AI settings schema/defaults/docs added; project .hatfield/settings.yaml actively defines provider/model settings; tests passed on PR branch.
