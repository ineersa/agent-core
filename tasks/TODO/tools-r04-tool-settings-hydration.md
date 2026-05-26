# TOOLS-R04 Tool settings hydration from Hatfield settings

## Goal
Plan source: `.pi/plans/toolbox-design-plan.md`.

Move tool execution settings out of hard-coded service arguments into Hatfield settings/defaults with project/home override support.

Dependencies:
- Depends on TOOLS-R02 (`HatfieldToolProviderInterface`, `ToolDefinitionDTO`, `BuiltInToolRegistrar`) so the registrar can read settings-derived defaults during registration if needed.
- Can land in parallel with TOOLS-R03 (registry-backed Toolbox) since settings are consumed by service factories, not by the Toolbox adapter itself.

Scope:
- Create `ToolSettings` (or `ToolSettingsDTO`): readonly DTO hydrating `tools.*` keys from `AppConfig::raw['tools']` with safe defaults.
- Settings shape (from plan):
  ```yaml
  tools:
    default_timeout_seconds: 120
    max_parallelism: 1
    termination_grace_seconds: 5
    bash:
      background_prompt_threshold_seconds: 30
    output_cap:
      max_chars: 20000
      max_doc_chars: 50000
      retention_seconds: 86400
    image:
      max_bytes: 10485760
      max_width: 4096
      max_height: 2000
  ```
- Wire existing services to read from `ToolSettings` instead of fixed YAML arguments:
  - `ToolExecutor` timeout/max parallelism defaults.
  - `ToolProcessTerminator` grace period (via TOOLS-00).
  - `OutputCap` constructor defaults (via TOOLS-02).
- Register `ToolSettings` as a service in `config/services.yaml`, constructed from `AppConfig`.
- Update `.hatfield/settings.yaml` comments with the new `tools:` section and defaults.
- Update `docs/settings.md` documenting each key, its default, and what it controls.
- Settings precedence: built-in defaults < `~/.hatfield/settings.yaml` < project `.hatfield/settings.yaml` (already handled by `AppConfig`).

Out of scope:
- Concrete tool implementations reading per-tool settings (each tool task handles its own settings consumption).
- Changing the settings merge/override mechanism itself (owned by `AppConfig`).

## Acceptance criteria
- `ToolSettings` DTO exists with all listed keys and safe defaults.
- `ToolSettings` is wired as a service constructed from `AppConfig::raw['tools']`.
- `ToolExecutor` reads default timeout and max parallelism from `ToolSettings` instead of hard-coded arguments.
- `.hatfield/settings.yaml` has documented `tools:` section with defaults.
- `docs/settings.md` documents each tool settings key.
- Project-level settings override home-level settings and built-in defaults.
- Focused tests cover DTO hydration, override precedence, and missing-key defaults.
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
