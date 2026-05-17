# TOOLS-10 Add prompt and docs integration for final toolbox

## Goal
Update prompts/docs to teach the model how to use the final toolbox.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on final tool names/schemas from TOOLS-03 (`write`), TOOLS-04 (`view_image`), TOOLS-06 (`edit`), TOOLS-07 (`read`), and TOOLS-09 (`bash`).

Scope:
- Find the system prompt / agent instruction assembly code in `src/CodingAgent/`.
- Add concise guidance:
  - Use `read` to examine files. Output uses `cat -n` line numbers.
  - Use those line numbers for unified diff `@@` headers in `edit`.
  - Use `edit` for targeted changes to existing files.
  - Use `write` for new files or full rewrites.
  - Use `view_image` for images; `read` is text-only.
  - Use `bash` for commands; long-running commands may be moved to background by user prompt; use `bg_status` for list/log/stop.
- Update docs/settings only if the task introduces user-facing settings. Otherwise keep docs minimal.
- Add/adjust tests for prompt assembly if such tests exist.

Out of scope:
- No tool implementation changes unless needed to align names/descriptions.
- No new settings unless already introduced by earlier tasks.

## Acceptance criteria
- Prompt/instructions mention the final tool names and intended usage accurately.
- Edit guidance explicitly says to provide standard unified diffs and use `read` line numbers for `@@` headers.
- Prompt does not claim model-controlled backgrounding; it explains `bg_status` for already-backgrounded processes.
- Existing prompt assembly tests pass or new focused tests cover the inserted guidance.
- Focused tests pass with Castor/PHPUnit.

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
- Created: 2026-05-17T04:42:49.755Z
