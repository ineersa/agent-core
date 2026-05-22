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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-22T00:39:54.580Z
