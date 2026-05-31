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
Status: DONE
Branch: task/system-02-agents-context-discovery
Worktree: /home/ineersa/projects/agent-core-worktrees/system-02-agents-context-discovery
Fork run: mbkjztwvhpx0
PR URL: https://github.com/ineersa/agent-core/pull/52
PR Status: merged
Started: 2026-05-26T00:48:00.937Z
Completed: 2026-05-26T01:13:28.840Z

## Work log
- Created: 2026-05-25T17:16:43.526Z

## Task workflow update - 2026-05-26T00:48:00.937Z
- Moved TODO → IN-PROGRESS.
- Created branch task/system-02-agents-context-discovery.
- Created worktree /home/ineersa/projects/agent-core-worktrees/system-02-agents-context-discovery.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/system-02-agents-context-discovery.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/system-02-agents-context-discovery.

## Task workflow update - 2026-05-26T00:53:23.088Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/system-02-agents-context-discovery to origin.
- branch 'task/system-02-agents-context-discovery' set up to track 'origin/task/system-02-agents-context-discovery'.
- Created PR: https://github.com/ineersa/agent-core/pull/52

## Task workflow update - 2026-05-26T01:08:07.603Z
- Recorded fork run: mbkjztwvhpx0
- Summary: Addressed all 4 PR review comments on PR #52:
1. Removed SYSTEM.md additions (context channel already documents <project_context>)
2. Added architecture comment clarifying both InProcess and controller paths flow through this single injection point
3. Added .agents/ folder support to AgentsContextDiscovery: checks .hatfield/ then .agents/ per directory, with 4 new tests covering global/project precedence
4. Replaced manual str_replace XML escaping with htmlspecialchars(ENT_XML1|ENT_QUOTES)

42 tests pass, 0 deptrac violations, 0 phpstan errors, cs-check clean.

## Task workflow update - 2026-05-26T01:08:50.072Z
- Summary: PR #52 review comments addressed: removed SYSTEM.md additions, added architecture comment clarifying single injection point, added .agents/ folder support (.hatfield/ takes precedence), replaced manual XML escaping with htmlspecialchars(ENT_XML1|ENT_QUOTES). 42/42 tests pass, deptrac/phpstan/cs-check clean.

## Task workflow update - 2026-05-26T01:13:28.840Z
- Moved CODE-REVIEW → DONE.
- Merged task/system-02-agents-context-discovery into integration checkout.
- Merge made by the 'ort' strategy.
 .../InProcess/InProcessAgentSessionClient.php      |  19 ++
 .../SystemPrompt/AgentsContextDiscovery.php        | 174 +++++++++++
 .../SystemPrompt/AgentsContextRenderer.php         |  65 ++++
 .../InProcess/AgentsContextInjectionTest.php       | 263 ++++++++++++++++
 .../SystemPrompt/AgentsContextDiscoveryTest.php    | 330 +++++++++++++++++++++
 .../SystemPrompt/AgentsContextRendererTest.php     | 140 +++++++++
 6 files changed, 991 insertions(+)
 create mode 100644 src/CodingAgent/SystemPrompt/AgentsContextDiscovery.php
 create mode 100644 src/CodingAgent/SystemPrompt/AgentsContextRenderer.php
 create mode 100644 tests/CodingAgent/Runtime/InProcess/AgentsContextInjectionTest.php
 create mode 100644 tests/CodingAgent/SystemPrompt/AgentsContextDiscoveryTest.php
 create mode 100644 tests/CodingAgent/SystemPrompt/AgentsContextRendererTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/system-02-agents-context-discovery.
- Pulled integration checkout: Merge made by the 'ort' strategy..
