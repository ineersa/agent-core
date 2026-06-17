# Fix issue #153 tool execution visibility bypass

## Goal
GitHub issue: https://github.com/ineersa/agent-core/issues/153

Problem: tool visibility/exclusion is enforced for model-facing schemas/listings but not for actual execution. `RegistryBackedToolbox::execute()` resolves through `ToolRegistry::toolDefinition()`, and scouts confirmed `toolDefinition()` currently returns registered permanent/dynamic definitions without applying `isToolVisible()`. `activeToolDefinitions()`/`activeToolNames()` are filtered, so excluded tools are hidden from discovery but can still execute through direct toolbox calls or execution paths without a `tools_ref` allowlist guard.

Scout recommendation: make the single-tool lookup used by execution visibility-aware (preferred: add an `isToolVisible()` guard in `ToolRegistry::toolDefinition()` and update interface/docblocks). `RegistryBackedToolbox::execute()` already handles `null` by throwing Symfony AI `ToolNotFoundException`, which is a clear unavailable-tool outcome and prevents handler/tool output from leaking.

Test target: `tests/CodingAgent/Tool/RegistryBackedToolboxTest.php` for the execution regression, optionally `tests/CodingAgent/Tool/ToolRegistryTest.php` for direct lookup semantics. Testing docs were loaded before creating this task.

## Acceptance criteria
- Excluded tools cannot execute through `RegistryBackedToolbox::execute()`; the handler must not be invoked.
- Allowlist-filtered tools are also treated as unavailable for execution.
- Existing schema/listing behavior remains unchanged (`getTools()`/active definitions still filter as before).
- Add regression coverage proving an excluded registered tool is rejected on execution with `ToolNotFoundException` or equivalent unavailable-tool behavior and no sensitive tool output leak.
- Run focused validation through Castor, at minimum `castor test --suite=coding-agent --filter=RegistryBackedToolboxTest` plus any targeted registry tests touched.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/issue-153-tool-execution-visibility
Worktree: /home/ineersa/projects/agent-core-worktrees/issue-153-tool-execution-visibility
Fork run: 206ynkox7fl5
PR URL:
PR Status:
Started: 2026-06-17T16:12:02.630Z
Completed:

## Work log
- Created: 2026-06-17T16:11:57.773Z

## Task workflow update - 2026-06-17T16:12:02.630Z
- Moved TODO → IN-PROGRESS.
- Created branch task/issue-153-tool-execution-visibility.
- Created worktree /home/ineersa/projects/agent-core-worktrees/issue-153-tool-execution-visibility.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/issue-153-tool-execution-visibility.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/issue-153-tool-execution-visibility.

## Task workflow update - 2026-06-17T16:12:29.185Z
- Recorded fork run: 206ynkox7fl5
- Summary: Implementation fork launched in worktree `/home/ineersa/projects/agent-core-worktrees/issue-153-tool-execution-visibility` to make tool execution visibility-aware and add regression coverage for issue #153. Fork instructed to read testing skill + tests/AGENTS.md, use Castor validation, commit changes, and hand off results.
