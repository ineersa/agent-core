# TOOLS-10 Add prompt and docs integration for final toolbox

## Goal
Update prompts/docs to teach the model how to use the final toolbox.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-R00, TOOLS-R02, TOOLS-R03, TOOLS-R04, and SYSTEM-01 for registry-aware prompt assembly, registry-backed Toolbox metadata, settings-backed tool defaults, and final built-in tool definition conventions.
- Depends on SYSTEM-02 for AGENTS.md project context discovery/injection behavior.
- Depends on SYSTEM-03 for skills registry/discovery/preload context behavior.
- Depends on final tool names/schemas from TOOLS-03 (`write`), TOOLS-04 (`view_image`), TOOLS-06 (`edit`), TOOLS-07 (`read`), TOOLS-08 (`bg_status`), and TOOLS-09 (`bash`).

Scope:
- Use the system prompt assembly code from SYSTEM-01 in `src/CodingAgent/`.
- Ensure docs/prompt wording matches SYSTEM-02: AGENTS.md is loaded as an initial user-context message for new sessions, not spliced into SYSTEM.md/system prompt.
- Ensure docs/prompt wording matches SYSTEM-03: skills are exposed through `<skills_instructions>`/`<available_skills>` in the initial user-context message, and `--skills` preloads skill bodies there rather than in the system prompt.
- Add final concise guidance:
  - Use `read` to examine files. Output uses `cat -n` line numbers.
  - Use those line numbers for unified diff `@@` headers in `edit`.
  - Use `edit` for targeted changes to existing files.
  - Use `write` for new files or full rewrites.
  - Use `view_image` for images; `read` is text-only.
  - Use `bash` for commands; long-running commands may be moved to background by user prompt; use `bg_status` for list/log/stop.
- Verify docs/settings already documents the tool settings introduced by TOOLS-R04 and concrete tool tasks; update if final prompt/docs wording reveals gaps.
- Add/adjust tests for prompt assembly if such tests exist.

Out of scope:
- No tool implementation changes unless needed to align names/descriptions.
- No new settings unless already introduced by TOOLS-R04 or concrete tool tasks.

## Acceptance criteria
- Prompt/instructions mention the final tool names and intended usage accurately.
- Edit guidance explicitly says to provide standard unified diffs and use `read` line numbers for `@@` headers.
- Prompt does not claim model-controlled backgrounding; it explains `bg_status` for already-backgrounded processes.
- Existing prompt assembly/context tests pass or new focused tests cover the inserted guidance, AGENTS.md context-channel wording, and skills context-channel wording.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-10-prompt-docs-integration
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration
Fork run: t3ff1nsgaa75
PR URL:
PR Status:
Started: 2026-06-16T22:05:47.991Z
Completed:

## Work log
- Created: 2026-05-17T04:42:49.755Z

## Task workflow update - 2026-06-16T22:05:47.991Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-10-prompt-docs-integration.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration.
- Summary: Started task-start workflow. Orchestrator will gather prompt/docs/test context and launch an implementation fork in the created worktree.

## Task workflow update - 2026-06-16T22:08:52.573Z
- Validation: Read `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` before proposing tests.; Scout recommended focused Castor tests: `castor test --filter SystemPromptBuilderTest`, tool definition tests for edit/read/write/bash/bg_status/view_image, context tests for AgentsContextRenderer/Discovery and SkillsContextBuilder, plus `castor deptrac`, `castor phpstan`, `castor cs-check`; opt-in `castor test:llm-real` because system prompt/tool guidance is LLM-visible.
- Summary: Read task and plan source `.pi/plans/toolbox-design-plan.md`; claimed worktree `/home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration`. Loaded task-workflow, subagents, and testing skills plus `tests/AGENTS.md` for validation conventions. Scout found prompt assembly in `config/SYSTEM.md` + `src/CodingAgent/SystemPrompt/SystemPromptBuilder.php`; initial context injection in `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php`; final tool prompt metadata in `src/CodingAgent/Tool/{ReadFileTool,EditFileTool,WriteFileTool,ViewImageTool,BashTool,BgStatusTool}.php`; settings docs in `docs/settings.md`, `config/hatfield.defaults.yaml`, `.hatfield/settings.yaml`; likely tests in `tests/CodingAgent/SystemPrompt/SystemPromptBuilderTest.php` and relevant tool/context tests. Key gap: `edit` guidance lacks explicit use of `read` line numbers for unified diff `@@` headers. Task is LLM-visible prompt/tool guidance, not a TUI feature; opt-in `castor test:llm-real` should be mentioned for focused provider validation.

## Task workflow update - 2026-06-16T22:09:39.237Z
- Recorded fork run: t3ff1nsgaa75
- Summary: Launched implementation fork `t3ff1nsgaa75` in worktree `/home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration`. Fork instructions require prompt/tool metadata/docs/test updates, focused Castor validation, optional `castor test:llm-real` for LLM-visible prompt changes, and a final commit on `task/tools-10-prompt-docs-integration`. Main agent will wait for fork handoff before verifying commit/diff and recording results.
