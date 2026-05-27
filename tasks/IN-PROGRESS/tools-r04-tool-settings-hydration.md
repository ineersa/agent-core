# TOOLS-R04 Tool settings hydration from Hatfield settings

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md`.

Verify and close the remaining tool settings work after TOOLS-00, TOOLS-02, and TOOLS-R03 moved the common execution/output-cap settings into typed Hatfield config DTOs and documented tool authoring conventions.

Dependencies:
- Depends on TOOLS-00 for `ToolExecutionConfig`, `ToolSettings`, `ToolExecutionSettingsInterface`, and ToolExecutor settings wiring.
- Depends on TOOLS-02 for `OutputCapConfig` and `ToolsConfig::outputCap`.
- Depends on TOOLS-R02 (`HatfieldToolProviderInterface`, `ToolDefinitionDTO`, `ToolHandlerInterface`) so concrete tool providers can read settings-derived defaults during registration if needed.
- Depends on TOOLS-R03 for `ToolRuntime`/cancellable process authoring documentation and final toolbox execution wiring.

Scope:
- Treat the following as already landed and verify they remain correct rather than reimplementing them:
  - `tools.execution.default_mode`, `timeout_seconds`, and `max_parallelism` hydrate through typed `ToolsConfig::execution` / `ToolExecutionConfig`.
  - `ToolSettings::fromAppConfig()` reads typed `AppConfig->tools->execution`, not `AppConfig::raw['tools']`.
  - `ToolExecutor` and `ToolExecutionPolicyResolver` consume `ToolExecutionSettingsInterface`.
  - `tools.output_cap.*` hydrates through typed `ToolsConfig::outputCap` / `OutputCapConfig` and is consumed by `OutputCap`.
- Do not introduce speculative concrete-tool settings yet. Bash/image/background settings belong in their concrete tool tasks when those values become real production inputs.
- Keep all known tool settings under typed DTOs reachable from `AppConfig->tools`; do not add new production reads from `AppConfig::raw` for known sections.
- Register any future settings DTO/service wiring through Symfony Serializer denormalization, following the `ToolsConfig`/`OutputCapConfig`/`ToolExecutionConfig` pattern.
- Update `.hatfield/settings.yaml` comments with only settings that are actually implemented.
- Update `docs/settings.md` documenting each implemented key, its default, and what it controls.
- Settings precedence remains: built-in defaults < `~/.hatfield/settings.yaml` < project `.hatfield/settings.yaml` (already handled by `AppConfig`).

Out of scope:
- Concrete tool behavior or speculative settings before those tools exist.
- Reintroducing a foreground process registry/runner or hardcoded process-grace settings outside concrete process-owning tools.
- Changing the settings merge/override mechanism itself (owned by `AppConfig`).

## Acceptance criteria
- Existing execution/output-cap settings are verified to use typed `AppConfig->tools` DTOs, not `AppConfig::raw`.
- No speculative concrete tool settings are introduced; future settings are deferred to concrete tool tasks.
- `config/hatfield.defaults.yaml`, `.hatfield/settings.yaml`, and `docs/settings.md` document only implemented keys and remain in sync.
- Project-level settings override home-level settings and built-in defaults through the already-landed `AppConfig` loader.
- Existing DTO hydration/consumer tests cover the landed settings; no new tests are needed for a no-op verification closeout.
- `castor deptrac` passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-r04-tool-settings-hydration
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-r04-tool-settings-hydration
Fork run:
PR URL:
PR Status:
Started: 2026-05-27T00:20:24.853Z
Completed:

## Work log
- Created: 2026-05-25T20:00:00.000Z — split from monolithic TOOLS-R02.

## Task workflow update - 2026-05-26T23:03:15.283Z
- Summary: Updated scope after TOOLS-00/TOOLS-02 merge: base execution settings and output-cap settings are already typed under AppConfig->tools, so R04 is now remaining settings consolidation/extension for concrete tool settings only, with no new production AppConfig::raw['tools'] reads.

## Task workflow update - 2026-05-27T00:20:24.853Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-r04-tool-settings-hydration.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-r04-tool-settings-hydration.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-r04-tool-settings-hydration.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-r04-tool-settings-hydration.
- Summary: Starting verification/no-op closeout: TOOLS-00/TOOLS-02/TOOLS-R03 already delivered typed execution/output-cap settings hydration; remaining concrete tool-specific settings will be handled by concrete tool tasks when those inputs become real.
