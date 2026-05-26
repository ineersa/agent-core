# SYSTEM-01 System prompt template overrides, append prompts, and injection

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md` section 6.

Depends on TOOLS-R00 for permanent tool prompt metadata snapshots.

Implement Hatfield's system prompt assembly exactly around `config/SYSTEM.md` and user/project override files. This is XML-ish text template rendering, not Markdown processing.

Scout notes:
- Symfony AI has `SystemMessage(string|Template)`, `Message::forSystem()`, `Template::string()`, `StringTemplateRenderer`, and `MessageBag::withSystemMessage()` that can be reused or mirrored.
- Do not use Symfony AI `SystemPromptInputProcessor` as-is because it appends Markdown `# Tools` from `Toolbox`; Hatfield needs registry-rendered `<available_tools>` and `<guidelines>` sections.

## Acceptance criteria
- `config/SYSTEM.md` is the built-in default template and is rendered with its current placeholders.
- `{cwd}/.hatfield/SYSTEM.md` replaces the built-in template completely when present; otherwise `~/.hatfield/SYSTEM.md` replaces it; otherwise the built-in is used.
- `~/.hatfield/APPEND_SYSTEM.md` and `{cwd}/.hatfield/APPEND_SYSTEM.md` are loaded when present, concatenated in that order, rendered with the same template renderer/variables except `{%appends_part%}` is empty to avoid recursion, and then inserted into `{%appends_part%}`.
- The renderer fills `{%available_tools_list%}`, `{%registered_guidelines%}`, `{%appends_part%}`, `{%date%}`, and `{%cwd%}`.
- Built-in, home override, and project override `SYSTEM.md` files are all rendered through the same template renderer and variable map; user-provided `SYSTEM.md` and `APPEND_SYSTEM.md` templates may use any subset of the supported placeholders.
- Available-tools lines and guidelines come from TOOLS-R00 permanent tool metadata snapshots and are stable/deduped.
- The rendered prompt is injected into LLM context as the first system message before the first user message; current acceptable path is prepending an `AgentMessage(role: 'system', ...)`.
- No AgentCore or TUI dependency on CodingAgent prompt internals is introduced.
- Focused tests cover built-in template rendering, home/project SYSTEM override precedence, user-provided SYSTEM and APPEND templates with supported placeholders, append prompt concatenation, dedupe rendering, and system-message injection.
- Validation includes focused PHPUnit/Castor tests and `castor deptrac`.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/system-01-system-prompt-template-overrides
Worktree: /home/ineersa/projects/agent-core-worktrees/system-01-system-prompt-template-overrides
Fork run: r0zhc0h7jq00
PR URL: https://github.com/ineersa/agent-core/pull/51
PR Status: open
Started: 2026-05-25T23:32:12.519Z
Completed:

## Work log
- Created: 2026-05-25T16:34:12.747Z

## Task workflow update - 2026-05-25T23:32:12.519Z
- Moved TODO → IN-PROGRESS.
- Created branch task/system-01-system-prompt-template-overrides.
- Created worktree /home/ineersa/projects/agent-core-worktrees/system-01-system-prompt-template-overrides.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/system-01-system-prompt-template-overrides.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/system-01-system-prompt-template-overrides.

## Task workflow update - 2026-05-25T23:38:41.366Z
- Recorded fork run: g5drakp1bp8i
- Summary: Implementation complete. Created SystemPromptBuilder service with template resolution (project > home > built-in), append template loading/rendering, placeholder substitution for all 5 vars, and system message injection into InProcessAgentSessionClient. 15 focused PHPUnit tests pass. All Castor quality checks pass (deptrac, phpstan, cs-check). Pre-existing ExtensionApi test failures unrelated.

## Task workflow update - 2026-05-25T23:38:55.385Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/system-01-system-prompt-template-overrides to origin.
- branch 'task/system-01-system-prompt-template-overrides' set up to track 'origin/task/system-01-system-prompt-template-overrides'.
- Created PR: https://github.com/ineersa/agent-core/pull/51

## Task workflow update - 2026-05-25T23:47:48.907Z
- Validation: 16 tests pass, 0 deptrac violations, phpstan clean, cs-check clean
- Summary: Addressed review findings: (1) added rtrim for CWD trailing slash bug, (2) removed dead variable in test, (3) fixed misleading docblock, (4) added AppSystemPrompt deptrac layer, (5) added trailing-slash regression test. All checks pass.

## Task workflow update - 2026-05-26T00:09:11.974Z
- Recorded fork run: r0zhc0h7jq00
- Summary: Addressed all 4 PR review comments: (1) replaced custom render() with Symfony AI StringTemplateRenderer, changed config/SYSTEM.md from {%var%} to {var} syntax; (2-4) injected SettingsPathResolver instead of duplicating home-dir logic, removed Windows fallbacks and getcwd(). 17 tests pass, deptrac/phpstan/cs-check clean.
