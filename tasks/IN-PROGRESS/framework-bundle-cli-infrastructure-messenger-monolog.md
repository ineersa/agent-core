# Adopt FrameworkBundle for CLI infrastructure, Messenger, and Monolog

## Goal
## Context

We decided the no-FrameworkBundle Symfony setup is getting in the way of real async Messenger usage and proper logging. `bin/console` currently exposes only the app `agent` command; there is no `messenger:consume`, `debug:messenger`, failure transport tooling, or normal FrameworkBundle Messenger wiring.

Current custom infrastructure to reassess/remove:

- `src/CodingAgent/Integration/MessengerIntegrationCompilerPass.php` recreates part of FrameworkBundle/MessengerBundle behavior.
- `config/packages/messenger.yaml` manually defines raw `MessageBus` services.
- logging/Monolog setup is custom/incomplete and has already caused vendor/bootstrap confusion during RTVS validation.
- AGENTS.md currently says not to add FrameworkBundle; this task intentionally revisits that rule.

Goal: adopt FrameworkBundle for CLI infrastructure only, without turning Hatfield into an HTTP app. We still do not want `public/index.php`, controllers, routing, HttpKernel request handling, or web stack behavior unless explicitly justified later.

This task may absorb or supersede the existing TODO `2026-05-18-add-monolog-logging-with-jsonl-format-exception-logging-and-castor-lo.md`; decide during implementation whether to merge/close/update that task rather than duplicating logging work.

## Proposed direction

- Add `symfony/framework-bundle` and `symfony/monolog-bundle` if needed.
- Register FrameworkBundle in `config/bundles.php` for CLI infrastructure.
- Configure `framework.messenger` normally so Symfony provides Messenger buses, senders/receivers, `messenger:consume`, `debug:messenger`, and failure/retry tooling.
- Configure Monolog through MonologBundle instead of custom handlers where practical.
- Keep app boundaries intact: `src/Tui/` must not depend on AgentCore/Messenger/Framework internals; enforce via deptrac.

## Notes / constraints

- This is a deliberate architecture policy change; update AGENTS.md and docs to say FrameworkBundle is allowed for CLI/container infrastructure while HTTP/router/public-index remain disallowed.
- Prefer normal Symfony config over custom compiler passes/services.
- Do not introduce HTTP controllers/routes/public entrypoints as part of this task.
- Preserve current CLI entrypoint behavior: `bin/console agent` still launches TUI by default and headless mode still works.

## Acceptance criteria
- FrameworkBundle is installed/registered and `bin/console` boots successfully without adding HTTP controllers, routes, or `public/index.php`.
- `bin/console list` exposes standard Messenger commands such as `messenger:consume` and `debug:messenger` (or any intentional omissions are documented with rationale).
- Replace raw manual Messenger bus wiring with `framework.messenger` config for `agent.command.bus` and `agent.execution.bus`.
- Remove `MessengerIntegrationCompilerPass` and custom `Kernel::build()` Messenger handler-registration logic if FrameworkBundle fully replaces it; otherwise document any remaining custom pass with a narrow reason.
- Configure Monolog through Symfony/MonologBundle for app logs, including JSONL or structured file output compatible with existing Castor log tasks.
- Reconcile the existing Monolog TODO task: update, close, or explicitly mark it as superseded by this FrameworkBundle task.
- Update AGENTS.md and relevant docs to reflect the new policy: FrameworkBundle allowed for CLI infrastructure; HTTP stack/router/public index still disallowed.
- Validate with Castor: `castor cache:clear`, `castor deptrac`, `castor test`, `castor phpstan` on changed paths, and `castor cs-check`.
- Because this touches runtime/container/CLI infrastructure, run a product-level workflow: `castor run:agent-test` or `castor test:tui`, and report session artifacts on failure.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/framework-bundle-cli-infrastructure-messenger-monolog
Worktree: /home/ineersa/projects/agent-core-worktrees/framework-bundle-cli-infrastructure-messenger-monolog
Fork run: 2yayxrwbk5nn
PR URL:
PR Status:
Started: 2026-05-22T00:40:50.133Z
Completed:

## Work log
- Created: 2026-05-22T00:39:54.580Z

## Task workflow update - 2026-05-22T00:40:50.133Z
- Moved TODO → IN-PROGRESS.
- Created branch task/framework-bundle-cli-infrastructure-messenger-monolog.
- Created worktree /home/ineersa/projects/agent-core-worktrees/framework-bundle-cli-infrastructure-messenger-monolog.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/framework-bundle-cli-infrastructure-messenger-monolog.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/framework-bundle-cli-infrastructure-messenger-monolog.
- Summary: Starting FrameworkBundle CLI infrastructure work: adopt FrameworkBundle for Messenger/Monolog, remove custom Messenger compiler pass where possible, preserve no-HTTP policy, and validate via Castor/product workflow.

## Task workflow update - 2026-05-22T00:41:06.871Z
- Recorded fork run: 2yayxrwbk5nn
- Summary: Launched implementation fork in worktree /home/ineersa/projects/agent-core-worktrees/framework-bundle-cli-infrastructure-messenger-monolog. Scope: adopt FrameworkBundle for CLI/container infrastructure, normal Messenger commands/config, MonologBundle logging, remove custom Messenger compiler pass where possible, update docs/task board, and run Castor/product validation.

## Task workflow update - 2026-05-22T00:56:00.440Z
- Recorded fork run: 2yayxrwbk5nn
- Validation: php bin/console list --raw | grep -E 'messenger|debug:messenger|agent' -> Messenger commands available including messenger:consume/debug:messenger plus agent/log commands (per fork).; php bin/console debug:messenger -> agent.command.bus and agent.execution.bus handlers wired (per fork).; castor deptrac -> 0 violations (per fork).; castor test -> 806 tests, 9558 assertions, all pass (per fork).; castor phpstan --path=src/CodingAgent -> no errors (per fork).; castor cs-check -> passes clean (per fork).; castor cache:clear -> passes (per fork).; castor run:agent-test -> TUI launches in tmux, agent responds with visible user+assistant transcript blocks, session artifacts present (per fork).
- Summary: Implementation fork completed and was merged with local main plan updates in the worktree. Final worktree HEAD: 6f3c7870 (merge), implementation commit: 19e4f534. Changes adopt FrameworkBundle for CLI infrastructure, normal framework.messenger bus wiring, MonologBundle service handler config, remove MessengerIntegrationCompilerPass, delete custom serializer.yaml, update AGENTS.md policy/deptrac, and mark the older Monolog TODO as superseded. The async headless Messenger plan update is included in the task branch via main merge.
