# TOOLS-R04 Tool settings hydration from Hatfield settings

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md`.

Consolidate the remaining tool settings work after TOOLS-00 and TOOLS-02 moved the common execution/output-cap settings into typed Hatfield config DTOs.

Dependencies:
- Depends on TOOLS-00 for `ToolExecutionConfig`, `ToolSettings`, `ToolExecutionSettingsInterface`, and ToolExecutor settings wiring.
- Depends on TOOLS-02 for `OutputCapConfig` and `ToolsConfig::outputCap`.
- Depends on TOOLS-R02 (`HatfieldToolProviderInterface`, `ToolDefinitionDTO`, `BuiltInToolRegistrar`) so concrete tool providers can read settings-derived defaults during registration if needed.
- Can land in parallel with TOOLS-R03 (registry-backed Toolbox) since settings are consumed by service factories and concrete tools, not by the Toolbox adapter itself.

Scope:
- Treat the following as already landed and verify they remain correct rather than reimplementing them:
  - `tools.execution.default_mode`, `timeout_seconds`, and `max_parallelism` hydrate through typed `ToolsConfig::execution` / `ToolExecutionConfig`.
  - `ToolSettings::fromAppConfig()` reads typed `AppConfig->tools->execution`, not `AppConfig::raw['tools']`.
  - `ToolExecutor` and `ToolExecutionPolicyResolver` consume `ToolExecutionSettingsInterface`.
  - `tools.output_cap.*` hydrates through typed `ToolsConfig::outputCap` / `OutputCapConfig` and is consumed by `OutputCap`.
- Add only remaining typed settings DTOs needed by concrete tool tasks as those settings become real production inputs, for example:
  ```yaml
  tools:
    bash:
      background_prompt_threshold_seconds: 30
      termination_grace_seconds: 5
    image:
      max_bytes: 10485760
      max_width: 4096
      max_height: 2000
  ```
- Keep all known tool settings under typed DTOs reachable from `AppConfig->tools`; do not add new production reads from `AppConfig::raw` for known sections.
- Register any new settings DTO/service wiring through Symfony Serializer denormalization, following the `ToolsConfig`/`OutputCapConfig`/`ToolExecutionConfig` pattern.
- Update `.hatfield/settings.yaml` comments with only settings that are actually implemented.
- Update `docs/settings.md` documenting each implemented key, its default, and what it controls.
- Settings precedence remains: built-in defaults < `~/.hatfield/settings.yaml` < project `.hatfield/settings.yaml` (already handled by `AppConfig`).

Out of scope:
- Concrete tool behavior beyond consuming typed settings from this task.
- Reintroducing `ToolProcessTerminator`, a foreground process registry, or hardcoded process-grace settings outside concrete process-owning tools.
- Changing the settings merge/override mechanism itself (owned by `AppConfig`).

## Acceptance criteria
- Existing execution/output-cap settings are verified to use typed `AppConfig->tools` DTOs, not `AppConfig::raw`.
- Any newly introduced concrete tool settings are represented by typed readonly DTOs with Symfony Serializer `SerializedName` mappings where needed.
- `config/hatfield.defaults.yaml`, `.hatfield/settings.yaml`, and `docs/settings.md` document only implemented keys and remain in sync.
- Project-level settings override home-level settings and built-in defaults.
- Focused tests cover DTO hydration, override precedence, missing-key defaults, and any new settings consumers.
- `castor deptrac` passes.

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
- Created: 2026-05-25T20:00:00.000Z — split from monolithic TOOLS-R02.

## Task workflow update - 2026-05-26T23:03:15.283Z
- Summary: Updated scope after TOOLS-00/TOOLS-02 merge: base execution settings and output-cap settings are already typed under AppConfig->tools, so R04 is now remaining settings consolidation/extension for concrete tool settings only, with no new production AppConfig::raw['tools'] reads.
