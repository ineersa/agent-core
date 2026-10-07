# Agent Core

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
- Before test work or runtime, TUI, Messenger, or database validation, load `.agents/skills/testing/SKILL.md` and read `tests/AGENTS.md`.
- Apply that prerequisite to every agent and fork. Test-related fork handoffs must confirm both reads.
- Never signal root-owned workers or processes tagged with `HATFIELD_SESSION_ID`.

## Task workflow

- Use the external board at `/home/ineersa/projects/agent-core-tasks`; never commit board metadata to this repository.
- Use task tools for transitions and metadata. Run `task_list` before tracked work.
- Before phase work or transitions, load the active `task-workflow` skill and its exact phase procedure.
- Reload the router and current procedure after phase changes or compaction; run `task_list` after compaction.
- Load the ownership procedure before delegation. Main coordinates, explores initial scope, and owns transitions.
- Keep one-off implementation that touches 1–2 files with main. Delegate every larger implementation slice to a fork.
- Keep scouts for exploration, reviewers for review, and researchers for web findings. All three stay read-only.
- Use one writer per worktree and explicit ownership handoffs. Independent review is required.
- Run focused validation during implementation. The CODE-REVIEW transition owns the full `castor check` gate; do not run it separately first.
