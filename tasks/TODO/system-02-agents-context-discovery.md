# SYSTEM-02 AGENTS.md project context discovery and new-session injection

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md` section 6.

Implement Hatfield AGENTS.md support as model-visible conversational context, not as system prompt content. Discovery should mirror Pi's path behavior but only support AGENTS.md/AGENTS.MD; no CLAUDE.md compatibility.

The loaded context is injected only for new sessions as a synthetic first message before the first real user message. It must not be re-injected on resume/replay.

## Acceptance criteria
- Supported filenames are exactly `AGENTS.md` and `AGENTS.MD`, checked in that order per directory; first match wins in a directory.
- Discovery checks `~/.hatfield/` first, then walks upward from `{cwd}` to filesystem root; no downward scan.
- Loaded files are ordered global first, then project/ancestor files nearest-to-farthest from `{cwd}`, deduped by resolved absolute path.
- Loaded AGENTS.md content is rendered into one XML-ish `<project_context>` message with one `<project_instructions path="...">` block per file.
- The context is injected as a model-visible first message before the first real user message for new sessions only; session resume/replay does not duplicate it.
- AGENTS.md content is not inserted into `SYSTEM.md`, `APPEND_SYSTEM.md`, or any system prompt placeholder.
- The injected message uses a clear user-context representation, preferably `AgentMessage(role: 'user-context', ...)` with metadata, or a normal user role with metadata if custom-role handling makes that safer.
- `config/SYSTEM.md` documents the context channel but does not include a project-context placeholder.
- Focused tests cover filename precedence, global/project/ancestor ordering, dedupe, no CLAUDE.md loading, XML wrapping, new-session injection, and no injection on resume.
- Validation includes focused Castor tests and `castor deptrac`.

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
- Created: 2026-05-25T17:16:43.526Z
