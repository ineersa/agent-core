# Agent Core

## Project rules

- Read the nearest nested `AGENTS.md` before editing an area. Local rules cannot weaken root rules.
- Implement finalized requirements only. Ask about unresolved product behavior or public APIs.
- Use the smallest solution and existing Symfony, Doctrine, Messenger, and TUI facilities.
- Do not bypass constructors in production. Keep test helpers in tests.
- Delete dead code and unsupported fallback paths. Add compatibility only when requested or required by a published API.
- Preserve comments explaining invariants, concurrency, lifecycle, or rationale.
- Use semantic type suffixes such as `Enum`, `DTO`, `Handler`, `Mapper`, `Provider`, and `Repository`.
- Propagate caught exceptions or log intentional local degradation. Never leave empty catches.
- Never reset the worktree, rewrite history, or force-push without approval. Inspect status, log, and diff first.
- Log structured events with `run_id`, `session_id`, `component`, and `event_type`. Never log raw prompts, tool output, environment values, credentials, or session bodies by default.
- Keep configuration in YAML except `config/bundles.php`. Prefer invokable Symfony commands.
- Do not add HTTP controllers, routes, web sessions, or web-serving FrameworkBundle features.
- Use Hatfield settings for themes. When adding settings, update `.hatfield/settings.yaml` and `docs/settings.md` together.
- Keep `session_id === run_id`. Use canonical session `events.jsonl`; do not add `metadata.yaml`.

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
- Load the ownership procedure before delegation. Main owns exploration and implementation by default.
- Keep scouts and reviewers read-only. Use a fork only for a bounded, independently implementable slice that saves meaningful work.
- Use one writer per worktree and explicit ownership handoffs. Independent review is required.
- Run focused validation during implementation. The CODE-REVIEW transition owns the full `castor check` gate; do not run it separately first.
