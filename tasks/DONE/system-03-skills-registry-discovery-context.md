# SYSTEM-03 Skills registry, discovery, preload, and context injection

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md` section 6.

Implement Hatfield skill discovery and registry modeled after Pi, but inject skills into the initial user-context message rather than the system prompt. This depends on SYSTEM-02 for the new-session context-message boundary and ordering.

Skills are exposed to the model through `<skills_instructions>` + `<available_skills>` and optionally preloaded through `--skills` as frontmatter-stripped `<skill>` blocks.

## Acceptance criteria
- A skill root is a directory containing `SKILL.md`; when a `SKILL.md` is found, that directory is treated as a skill and recursion does not continue inside it.
- `SKILL.md` frontmatter supports `name`, required `description`, and optional `disable-model-invocation: true`; missing `name` defaults to the parent directory name.
- Discovery precedence is first-wins by skill name: repeatable `--skills-path <path>` entries first, then `{cwd}/.hatfield/skills`, `{cwd}/.agents/skills`, `~/.hatfield/skills`, `~/.agents/skills`, then later extension/package/settings paths when available.
- `--no-skills` disables auto-discovery but still allows explicit `--skills-path` entries.
- `--skills <name>` is repeatable and preloads resolved skill contents into the initial user-context message; comma-separated values may be accepted as a convenience but repeatable flags define the semantics.
- The initial user-context message order is: SYSTEM-02 `<project_context>`, then `<skills_instructions>` containing `<available_skills>`, then preloaded `<skill name="..." location="...">` bodies in CLI order.
- `<available_skills>` includes only model-invocable skills and lists name, description, and absolute `SKILL.md` location; disabled skills are excluded from the registry summary but can still be explicitly preloaded.
- Preloaded skill bodies strip YAML frontmatter, include `References are relative to ...`, preserve body text, and are never inserted into `SYSTEM.md` or a system role message.
- Skill name collisions are recorded as startup diagnostics with winner and ignored path; first discovered skill wins deterministically.
- TUI startup/header/status area shows a stable skills line such as `skills   skill:castor  skill:subagents` plus a way to surface skill source/collision diagnostics.
- Transcript rendering work is handed off/documented so preloaded `<skill>` blocks render as SKILL/context blocks rather than ordinary read-tool output.
- Focused tests cover discovery recursion, precedence, `--no-skills`, `--skills-path`, `--skills` preload ordering, frontmatter stripping, disabled skill behavior, collisions, context-message injection, and no injection on resume.
- Validation includes focused Castor tests and `castor deptrac`.

## Workflow metadata
Status: DONE
Branch: task/system-03-skills-registry-discovery-context
Worktree: /home/ineersa/projects/agent-core-worktrees/system-03-skills-registry-discovery-context
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/53
PR Status: merged
Started: 2026-05-26T01:15:13.089Z
Completed: 2026-05-26T03:06:30.689Z

## Work log
- Created: 2026-05-25T17:58:35.921Z

## Task workflow update - 2026-05-26T01:15:13.089Z
- Moved TODO → IN-PROGRESS.
- Created branch task/system-03-skills-registry-discovery-context.
- Created worktree /home/ineersa/projects/agent-core-worktrees/system-03-skills-registry-discovery-context.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/system-03-skills-registry-discovery-context.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/system-03-skills-registry-discovery-context.

## Task workflow update - 2026-05-26T01:24:15.176Z
- Summary: SYSTEM-03 implemented: 6 new classes (SkillDefinition, SkillsConfig, SkillDiscovery, SkillRegistry, SkillContextRenderer, SkillsContextBuilder), 3 CLI options (--no-skills, --skills-path, --skills), injection into InProcessAgentSessionClient. 87/87 tests pass, 0 deptrac violations, 0 phpstan errors, cs-check clean. Commit 034a741a pushed.

## Task workflow update - 2026-05-26T01:24:32.203Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/system-03-skills-registry-discovery-context to origin.
- branch 'task/system-03-skills-registry-discovery-context' set up to track 'origin/task/system-03-skills-registry-discovery-context'.
- Created PR: https://github.com/ineersa/agent-core/pull/53

## Task workflow update - 2026-05-26T01:35:19.858Z
- Summary: Review findings addressed: removed @ suppression in SkillRegistry::readBody(), added final readonly to renderer/builder, fixed misleading comment, 3 new edge-case tests (disabled preload, home discovery, malformed YAML). 47/47 tests pass, deptrac/phpstan/cs-check clean.

## Task workflow update - 2026-05-26T03:06:30.689Z
- Moved CODE-REVIEW → DONE.
- Merged task/system-03-skills-registry-discovery-context into integration checkout.
- Merge made by the 'ort' strategy.
 depfile.yaml                                       |  11 +
 phpstan-baseline.neon                              |  68 +---
 src/CodingAgent/CLI/AgentCommand.php               |  17 +
 .../InProcess/InProcessAgentSessionClient.php      |  15 +
 src/CodingAgent/Skills/SkillContextRenderer.php    |  71 ++++
 src/CodingAgent/Skills/SkillDefinition.php         |  30 ++
 src/CodingAgent/Skills/SkillDiscovery.php          | 289 ++++++++++++++++
 src/CodingAgent/Skills/SkillRegistry.php           |  97 ++++++
 src/CodingAgent/Skills/SkillsConfig.php            |  25 ++
 src/CodingAgent/Skills/SkillsContextBuilder.php    |  62 ++++
 .../InProcess/AgentsContextInjectionTest.php       |  24 +-
 .../InProcess/SkillsContextInjectionTest.php       | 344 +++++++++++++++++++
 .../Skills/SkillContextRendererTest.php            | 142 ++++++++
 tests/CodingAgent/Skills/SkillDiscoveryTest.php    | 374 +++++++++++++++++++++
 tests/CodingAgent/Skills/SkillRegistryTest.php     | 204 +++++++++++
 .../Skills/SkillsContextBuilderTest.php            | 201 +++++++++++
 16 files changed, 1909 insertions(+), 65 deletions(-)
 create mode 100644 src/CodingAgent/Skills/SkillContextRenderer.php
 create mode 100644 src/CodingAgent/Skills/SkillDefinition.php
 create mode 100644 src/CodingAgent/Skills/SkillDiscovery.php
 create mode 100644 src/CodingAgent/Skills/SkillRegistry.php
 create mode 100644 src/CodingAgent/Skills/SkillsConfig.php
 create mode 100644 src/CodingAgent/Skills/SkillsContextBuilder.php
 create mode 100644 tests/CodingAgent/Runtime/InProcess/SkillsContextInjectionTest.php
 create mode 100644 tests/CodingAgent/Skills/SkillContextRendererTest.php
 create mode 100644 tests/CodingAgent/Skills/SkillDiscoveryTest.php
 create mode 100644 tests/CodingAgent/Skills/SkillRegistryTest.php
 create mode 100644 tests/CodingAgent/Skills/SkillsContextBuilderTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/system-03-skills-registry-discovery-context.
- Pulled integration checkout: Merge made by the 'ort' strategy..
