# Add Monolog logging with JSONL format, exception logging, and Castor log tasks

## SUPERSEDED

This task is superseded by `framework-bundle-cli-infrastructure-messenger-monolog`.

What was done:
- `monolog/monolog` is installed.
- `HatfieldRotatingLogHandler`, `LogEntry`, `LogParser`, `LogFilter`, `LogReader` created.
- Castor log tasks (`log:tail`, `log:search`, `log:files`, `log:clear`) exist.
- Logger injection in AgentCommand, InteractiveMode, RuntimeEventPoller, RunTracer, RunCommit exists.

What the FrameworkBundle task changed:
- Switched from raw `Monolog\Logger` DI wiring to MonologBundle config.
- `config/packages/monolog.yaml` now uses `monolog.handlers.main.type: service` referencing the existing `HatfieldRotatingLogHandler`.
- `Psr\Log\LoggerInterface` is now aliased by MonologBundle automatically.

## Original metadata (preserved for history)
## Goal
## Plan
See `.pi/plans/logging-plan.md` for the full plan.

## Summary
1. Install `monolog/monolog`, create `HatfieldRotatingLogHandler` (daily rotation, JsonFormatter with stack traces)
2. Wire in `config/packages/monolog.yaml` — alias `Psr\Log\LoggerInterface` to `Monolog\Logger`
3. Add exception logging in `AgentCommand`, `InteractiveMode`, `RuntimeEventPoller` (non-nullable `LoggerInterface`)
4. Clean up existing `?LoggerInterface` to `LoggerInterface` in `RunTracer`, `RunCommit`
5. Create `LogEntry`, `LogParser`, `LogFilter`, `LogReader` in `src/CodingAgent/Logging/`
6. Add Castor tasks: `log:tail`, `log:search`, `log:files`, `log:clear`
7. Unit tests for parser, reader, filter

## Key decisions
- No MonologBundle (no FrameworkBundle) — direct DI wiring
- JSONL only, `.hatfield/logs/` (CWD-relative, PHAR-safe)
- Non-nullable `LoggerInterface` — the container always provides a real logger
- Castor tasks instantiate LogReader directly (no kernel boot needed)

## Acceptance criteria
- castor check passes (deptrac + phpunit + phpstan + cs-fixer)
- composer require monolog/monolog completed
- HatfieldRotatingLogHandler writes JSONL to .hatfield/logs/ with daily rotation
- Psr\Log\LoggerInterface aliased to Monolog\Logger in config/packages/monolog.yaml
- Unhandled exceptions logged in AgentCommand, InteractiveMode, RuntimeEventPoller
- Existing ?LoggerInterface params made non-nullable in RunTracer and RunCommit
- LogEntry, LogParser, LogFilter, LogReader implemented with unit tests
- castor log:tail shows recent entries as a table with --level, --search, --lines options
- castor log:search searches across log files with --level, --from, --to options
- castor log:files lists log files with size and modified date
- castor log:clear removes old rotated logs

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
- Created: 2026-05-18T03:17:44.280Z
