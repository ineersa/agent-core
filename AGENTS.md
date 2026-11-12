# Agent Core

## Documentation index

- Usage and configuration: [docs index](docs/README.md), [settings](docs/settings.md), [model/provider settings](docs/settings-models.md).
- Agent workflows: [agents](docs/agents.md), [skills](docs/skills.md), [tools](docs/tools.md), [background work and schedules](docs/background-processes.md).
- Extension authoring: [public Extension API](.hatfield/extensions/extension-api/docs/extension-api.md).
- Implementation: [architecture index](architecture/README.md), [processes and cooperative consumers](architecture/processes-and-queues.md), [code mode and scheduling](architecture/code-mode-and-scheduling.md).
- Validation and releases: [TUI testing](docs/tui-testing.md), [distribution](docs/distribution.md), [logging setup](docs/datadog.md).
- `hatfield_docs` exposes curated operational guides, not architecture internals. Use `list`, then `read` by ID; do not assume every Markdown file is bundled.

## Project rules

- Read the nearest nested `AGENTS.md` before editing an area. Local rules cannot weaken root rules.
- Implement finalized requirements only. Ask about unresolved product behavior or public APIs.
- Use the smallest solution. Keep configuration in YAML except `config/bundles.php`. Prefer invokable Symfony commands.
- Delete dead code and unsupported fallback paths. Add compatibility only when requested or required by a published API.
- Do not add HTTP controllers, routes, web sessions, or web-serving FrameworkBundle features.
- Use Hatfield settings for themes. When adding settings, update `.hatfield/settings.yaml` and `docs/settings.md` together.
- Keep `session_id === run_id`. Use canonical session `events.jsonl`; do not add `metadata.yaml`.

## Development rules

- Do not delete comments that explain non-obvious logic, invariants, concurrency, lifecycle, or rationale unless that logic is removed; update them when code changes. Drop only noise that restates the obvious.
- **Never run `git reset --hard`, rewrite history, reset the working tree, or force-push without explicit user approval.** Inspect first with `git status`, `git log --oneline --decorate -5`, and `git diff`. Prefer `git revert`, `git restore <file>`, or `git merge --abort`. If you cannot name exactly what would be lost, do not proceed.
- Do not add backward-compatibility code during active development unless the user asks or the code belongs to a published API such as `ExtensionApi` with a documented deprecation window. Replace old behavior and update its tests and docs.
- Semantic type suffixes: `EventTypeEnum`, `UserEventService`, `RuntimeEventMapper`, `SettingsProvider`, `TranscriptProjector`, `Repository`, `Factory`, `DTO`, etc.
- **MUST use existing project and framework facilities instead of custom or lower-level replacements unless the user explicitly approves an exception.** This includes Symfony components, Doctrine ORM, Serializer, Validator, EventDispatcher, Messenger, Lock, and the project TUI abstractions.
- Keep PRs focused and reviewable; agree on smaller slices before implementation grows into a large cross-cutting change. Exclude unrelated changes.
- Keep CodingAgent policy out of AgentCore. Extend generic building blocks and reuse existing orchestration instead of adding parallel coordinators or mirroring native execution state.
- Keep DTOs data-only in `DTO` namespaces, without interfaces or constructor validation. Put validators in `Validator`/`Validation`, exceptions in `Exception` with an `Exception` suffix, and import types instead of embedding fully qualified names.
- Use Symfony Serializer and Validator at input boundaries; return typed validation errors or refusals instead of generic exceptions or magic strings. Simplify complex predicates rather than multiplying state-combination tests around them.
- Generate one metadata-based Doctrine migration for an unmerged feature's final schema; never hand-write migrations. Put architecture explanations in `architecture/`, Hatfield usage guidance in `docs/`, and omit task/checkpoint labels from shipped documentation.
- No production APIs or paths solely for tests. No `ReflectionClass::newInstanceWithoutConstructor()`, `Closure::bind()`, or constructor bypass in production. Test helpers stay in tests.
- Every caught exception must be rethrown/propagated or explicitly logged as intentional local degradation. Empty catch blocks are forbidden.
- Runtime logs: structured event-style messages with correlation fields (`run_id`, `session_id`, `component`, `event_type`); do not log raw prompts, tool output, env values, API keys, or full session content by default. See `docs/datadog.md`.

## Architecture

- Treat `depfile.yaml` and `castor deptrac` as authoritative. Do not invent stricter blanket dependency bans.
- Keep AgentCore independent of CodingAgent, TUI, and extensions.
- Keep CodingAgent independent of TUI except Deptrac-approved bootstrap code. Never depend on concrete extensions; TUI may use owning CodingAgent services within Deptrac rules.
- Do not invent Runtime Contract wrappers merely to hide an allowed CodingAgent dependency.
- Keep ExtensionApi independent of host internals and concrete extensions. Concrete extensions use public contracts and explicitly approved vendor APIs only.

## Validation

- Run all QA, tests, lint, analysis, formatting, and docs validation through Castor in the exact checkout.
- Use raw `vendor/bin/*` only to isolate a Castor failure.
- **Never overwrite, truncate, delete, or replace real user or project settings for tests, diagnostics, or fixture setup.** This applies to main, forks, subagents, and their subprocesses. In particular, never write test defaults to the user's `~/.hatfield/settings.yaml`.
- Use the existing Castor/test-harness HOME and directory isolation helpers. Do not recreate their setup with ad hoc shell commands or redirect fixtures through inherited `$HOME`. Before writing a fixture, verify that its resolved destination is inside the disposable test directory and cannot resolve through a symlink to real configuration.
- A shell prefix such as `HOME=<temporary-directory> command` affects only that command; it does not change `$HOME` for subsequent commands after `&&` or `;`. Never rely on it to isolate later file writes. Main must enforce these settings-safety rules in delegated test work.
- Rewrite timing-dependent or flaky tests to use deterministic synchronization, or delete them. Never repair them with sleeps, timing-based polling, retries, or increased timeouts. Missing coverage is preferable to flaky coverage; record the coverage lost when deleting a test.
- Before test work or runtime, TUI, Messenger, or database validation, load `.agents/skills/testing/SKILL.md` and read `tests/AGENTS.md`.
- Apply that prerequisite to every agent and fork. Test-related fork handoffs must confirm both reads.
- Never signal root-owned workers or processes tagged with `HATFIELD_SESSION_ID`.
- **Never issue `tmux kill-session` or `tmux kill-server` from agent commands, delegated agents, or ad hoc cleanup scripts, even when a diagnostic suggests doing so.** Never derive destructive cleanup targets from pane lookups, command substitution, or session-name patterns. A missing or stale target can destroy the user's session and the running agent. Existing automated Castor/test-harness teardown remains permitted under its built-in ownership checks; this is not permission to invoke its destructive commands manually.
- Exit test TUIs through their own application controls in the exact pane recorded at launch, after verifying that the pane still belongs to the test. If ownership or target identity is uncertain, leave the pane alone and report the uncertainty. Main must enforce these restrictions in every delegated tmux task.

## Task workflow

- Main alone manages tracked workflow and loads the active `task-workflow` skill. Use the configured GitHub issue as the current specification, plan, decisions, test plan and QA results; Workflow is authoritative. List only when choosing work or resolving identity. Use task tools for transitions, not manual status edits.
- Implement directly or use optional bounded implementation forks, one writer per checkout. Forks follow development, architecture and testing rules and return changes, blockers and relevant QA. They do not manage task updates, transitions, other agents, reviews, PRs or commits.
- Transition hooks own the full validation gate and project cleanup; do not duplicate the full gate as a separate workflow ceremony. Honor project review and merge authorization. Report terminal success only after confirmed cleanup, registry removal and directory absence.
