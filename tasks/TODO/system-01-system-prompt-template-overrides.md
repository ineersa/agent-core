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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-25T16:34:12.747Z
