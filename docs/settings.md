---
builtin: true
description: Settings precedence, file locations, core runtime keys, and environment overrides.
---

# Hatfield Settings

Hatfield configuration is sparse YAML: built-in defaults apply until you override keys
in home or project settings. Hatfield does **not** copy defaults into your home directory.

## Interactive setup

Run `hatfield setup` for Settings, Providers, Extensions, Skills, and Agents. It defaults to user
settings; `hatfield setup --project` opens in project scope. Press **F2** to switch
scope, **Tab** or **Shift+Tab** to switch tabs, and **Esc** to go back or exit.
In Settings, confirming a valid edit saves it to the selected scope immediately.
**● Set here** means the selected scope has an override; **○ Not set here**
means it does not. The markers describe where the setting is written, not
which value takes precedence. When the footer shows **d**, press it to choose
an override to remove from that scope.
Defaults are shown in field help; editors distinguish the effective source from
the value in the scope being edited. Extension selections and favorite models use
multiselects: **Space** toggles, **Enter** confirms, **Esc** cancels. Open-ended
collections such as extra prompt paths still accept one-line YAML, for example
`[~/team/prompts]`.
Catalog-provider and Extension actions save when confirmed. Custom providers use
**Connection**, **Models**, and **Advanced compatibility** sections with an explicit
**Save changes** or **Save and enable** action. **Apply model to draft** stages a model
without saving the provider. Enable/disable changes preserve other provider overrides.
Esc cancels an open editor or unsaved provider draft. Package installations still require confirmation. Saving rewrites YAML and
does not preserve comments or formatting. Start a new session to load changes.

## Precedence

Later sources win via structured overlay (`AppConfigLoader`):

1. AI catalog (`~/.hatfield/ai-catalog.yaml`, bootstrapped from bundled `config/ai-catalog.yaml`; providers ship disabled until enabled via settings or `setup`)
2. Built-in defaults shipped with the install (`config/hatfield.defaults.yaml`)
3. `~/.hatfield/settings.yaml`
4. `<project>/.hatfield/settings.yaml`

Merge rules:

- **Associative maps** deep-merge (higher layer overrides matching keys; untouched lower keys survive).
- **Provider `models:` maps** replace wholesale (pin/trim); they do not deep-merge individual model ids.
- **Lists** (sequential arrays) are replaced entirely by the higher layer — no append/index-merge.
- **Scalars** (and `null`) in a higher layer replace the lower value.

Known providers in user/project settings are **sparse overlays** (for example
`{ enabled: true, api_key: env:ZAI_API_KEY }`). Full model lists and connection
defaults come from the AI catalog. Catalog bootstrap does **not** auto-enable
providers. See [ai-catalog.md](ai-catalog.md) for the precedence diagram,
`hatfield providers:update`, manual `setup`, and version-skew warning.

Use only keys you intend to change. Full snapshots of defaults are unnecessary.

Related focused references:

- AI catalog → [ai-catalog.md](ai-catalog.md)
- Models and providers → [settings-models.md](settings-models.md)
- Agents, prompts, skills, extensions → [settings-agents.md](settings-agents.md)
- Skills behavior (`/skill:`, on-demand-only) → [skills.md](skills.md)
- Sessions → [session-storage.md](session-storage.md)
- Compaction → [compaction.md](compaction.md)
- Approvals / SafeGuard → [approvals.md](approvals.md)
- Background processes → [background-processes.md](background-processes.md)

## Directory layout

| Path | Role |
|---|---|
| `~/.hatfield/ai-catalog.yaml` | User AI catalog (see [ai-catalog.md](ai-catalog.md)) |
| `~/.hatfield/settings.yaml` | User overrides |
| `~/.hatfield/chatgpt-auth.json` | Single-account verified ChatGPT grant; use `auth:chatgpt login` |
| `~/.hatfield/auth.json` | Grok credentials and retained legacy records; not used for ChatGPT |
| `<cwd>/.hatfield/settings.yaml` | Project overrides |
| `<cwd>/.hatfield/sessions/` | Session storage (ignored by git) |
| `<cwd>/.hatfield/tmp/` | Tool/output-cap/bg temp data |
| `<cwd>/.hatfield/logs/` | Application logs (default `logging.path`) |
| `~/.hatfield/extensions/`, `<cwd>/.hatfield/extensions/` | User-wide and project Composer extension installations |
| `~/.hatfield/skills/` | User skills, including materialized built-in skills (see [skills.md](skills.md)) |
| `~/.hatfield/agents/`, `<cwd>/.hatfield/agents/` | Agent definitions |
| `~/.hatfield/prompts/`, `<cwd>/.hatfield/prompts/` | Prompt templates |
| `~/.hatfield/mcp.json`, `<cwd>/.hatfield/mcp.json` | MCP servers |

Tracked monorepo `.hatfield/` may include example settings and extensions; runtime dirs stay local.

## Path resolution

Relative paths in settings resolve against the **active project CWD** (not the install/PHAR path).
`~` expands to the user home. Absolute paths are used as-is.

## Core keys

### TUI

| Key | Meaning | Default (built-in) |
|---|---|---|
| `tui.theme` | Theme id | `cyberpunk` |
| `tui.theme_paths` | Theme YAML search paths (list replace) | `%kernel.project_dir%/config/themes`, `.hatfield/themes`, `~/.hatfield/themes` |
| `tui.transcript.thinking.visible` | Show thinking blocks | `true` |
| `tui.transcript.thinking.style` | Thinking presentation | `dim_italic` |
| `tui.transcript.previews.expanded_by_default` | Expand previews | `false` |
| `tui.transcript.previews.tool_result_lines` | Tool result preview lines | `8` |
| `tui.transcript.previews.diff_lines` | Diff preview lines | `20` |

Theme colour tokens that are missing or empty render with the default terminal foreground. A colour or var that Symfony TUI cannot parse (a non-string value, malformed hex, or unknown name such as an undefined var) is dropped when the theme loads and logs a `tui.theme.invalid_color` warning naming the file and key; that token then renders unstyled.

### Sessions and logging

| Key | Meaning | Default |
|---|---|---|
| `sessions.path` | Base directory for sessions (relative → CWD) | `.hatfield/sessions` |
| `logging.path` | Log directory (relative → CWD) | `.hatfield/logs` |
| `logging.level` | Minimum PSR-3 level: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` | `warning` |
| `logging.max_files` | Daily rotated log files to retain | `2` |

### ChatGPT reasoning effort

ChatGPT sends the selected reasoning level through request-level `reasoning.effort`. Effort changes can reduce prompt-cache reuse. Native inline configuration updates are unsupported.

### Compaction (summary)

| Key | Meaning | Default |
|---|---|---|
| `compaction.auto_enabled` | Automatic compaction (manual `/compact` is always available) | `true` |
| `compaction.compact_after_tokens` | Absolute token threshold | `250000` |
| `compaction.keep_recent_tokens` | Retained tail | `20000` |
| `compaction.model` / `thinking_level` | Compaction model selection; `null` uses the session's | `null` |
| `compaction.provider_overrides` / `model_overrides` | Sparse overrides | empty |

Full compaction behavior: [compaction.md](compaction.md).

### Context-budget reminders

| Key | Meaning |
|---|---|
| `context_budget_reminders.early_input_tokens` | Early wrap-up advisory threshold. Default `200000`. |
| `context_budget_reminders.urgent_remaining_tokens` | Urgent remaining-token reserve. Default `25000`. |
| `context_budget_reminders.disable_for_main` | Suppress early and urgent reminders in parent sessions. Default `true`. |
| `context_budget_reminders.disable_for_forks` | Suppress early and urgent reminders in forks. Default `false`. |
| `context_budget_reminders.disable_for_subagents` | Suppress early and urgent reminders in named subagents. Default `false`. |

Parent reminders are off by default. Fork and subagent reminders remain on by default. Each flag affects only its run kind. Reminder flags and thresholds do not control automatic compaction or context-limit enforcement.

### Runtime policy

The LLM consumer has an internal ten-slot admission limit. Eligible parallel tool
calls have no numeric batch limit; registered sequential and interrupt barriers
still apply. These are runtime policy, not user settings. The Amp HTTP host
connection pool is independent of LLM admission.

The shared tool consumer reads both `tool` and `agent` queues, including retained
agent messages after a controller restart. It has no worker-level concurrency cap.
Child launch returns once deferred completion is registered rather than waiting
for the child's lifetime. Synchronous filesystem, database and CPU work can
still block sibling Fibers; concurrency does not make every tool non-blocking.

The shared tool process sends Bash, code-mode and child lifecycle metadata writes
to `run_control` and reads scalar snapshots. It does not retain managed entities
across tool waits or recycle after a fixed number of messages. Services are not
reset beneath active Fibers. The normal worker memory limit remains in effect.
Metadata redelivery does not repeat an external launch or resume action; it does
not guarantee exactly-once external tool effects.
The code-mode handler registers deferred correlation when execution begins and
marks readiness locally after accepting the script socket. Permission refusals
do not allocate execution metadata.

### Tool execution

There is **no** global tool timeout. Blocking tools own their deadlines
(bash, subagent, MCP, per-tool registration budgets). `ToolExecutor` does not rewrite
a successful late result into a timeout failure.

| Key | Meaning | Default |
|---|---|---|
| `forks.enabled` | Retained setting. Fork launches are currently unavailable and no setting can re-enable them. Named subagents are unaffected. | `true` |

### Output cap

| Key | Meaning | Default |
|---|---|---|
| `tools.output_cap.default_cap` | Non-doc char cap | `20000` |
| `tools.output_cap.doc_cap` | Doc-like char cap | `50000` |

Artifacts use `.hatfield/tmp/output-cap` under the active project CWD. Orphan
cleanup uses an internal 24-hour retention; lifecycle cleanup runs earlier.

### Images

| Key | Default |
|---|---|
| `tools.image.max_bytes` | `10485760` |
| `tools.image.max_width` | `4096` |
| `tools.image.max_height` | `2000` |

Resize/encoding policy is internal: 2000-pixel target, 4.5 MiB encoded budget,
initial quality 80 and minimum quality 40. Input rejection limits remain configurable.

### Bash / background

| Key | Default |
|---|---|
| `tools.bash.default_timeout_seconds` | `30` |
| `tools.bash.max_timeout_seconds` | `null` (uncapped; set a positive integer for an operator cap) |
| `tools.bash.log_tail_chars` | `20000` (maximum log characters returned as command output) |
| `tools.background_process.log_tail_chars` | `5000` |

Background logs use `.hatfield/tmp/bg` under the active project CWD. Supervision
cadence (100 ms) and graceful-stop allowance (5 seconds) are internal policy.

### Removed settings

`tools.execution.*`, `runtime.llm_concurrency` and `ai.http.*` are no longer
supported settings. Tool scheduling and HTTP reliability are application policy.
The former image encoding/resize, Bash polling, artifact path/retention and
background stop-grace keys described above are also no longer configurable.
Remove these keys from existing overrides; Hatfield does not rewrite settings on startup.
For forks, combine the former `extensions.always_on` selection into
`forks.extensions.enabled`. Named agents retain their separate always-on baseline.
Provider `api` and `compatibility.supports_developer_role` controls are removed;
model `max_tokens` may remain descriptive catalog metadata but is not an enforced
output limit or an editable setup control.

Compaction and early reminder thresholds remain absolute, independently
configurable values (250000 and 200000 tokens). Transcript preview lengths and
thinking style remain configurable.

### code_mode

| Key | Meaning | Default |
|---|---|---|
| `tools.code_mode.enabled` | Make the `code_mode` tool visible and callable | `false` |

`code_mode` runs arbitrary PHP in an owned subprocess. Keep it disabled until you
want that capability. Direct PHP filesystem and process calls inside the script
bypass toolbox hooks and approvals. Any launcher sandbox, such as `hatfield-safe`
with bubblewrap, is inherited rather than created by the tool. Scripts use a
1800-second wall budget by default (`timeout_seconds`, no tool-specific maximum)
and a 256 MiB PHP memory limit (`memory_limit_mb`, max 1024). Nested handlers remain cooperative
while blocked. Checkout and PHAR reuse the launching PHP CLI. Fused/static binaries
still need `php` on `PATH`. See [tools.md](tools.md).

See [background-processes.md](background-processes.md).

### Observational memory retrieval

OM requires `extensions.settings.observational_memory.librarian.url`, the full absolute
HTTP(S) MCP endpoint without credentials, query or fragment. `timeout_seconds`
defaults to 30 and is bounded to 1–300. Optional `token` accepts a bearer token or
`env:NAME` reference and is shared by MCP retrieval and the content-free HTTP OM API;
omit it for anonymous local servers. No second URL is configured.

Librarian owns observation, reflection, storage and journal-backed evidence. Hatfield
sends only terminal boundary identifiers asynchronously and reads current-session
projections for compaction and `/om-view`. Unavailable or empty projections continue
ordinary compaction. `memory_search` and ID-only `recall` stay project-scoped MCP tools.
There is no local retrieval fallback, local OM database writer, `/om-status`, or
activity poller. Linked Git worktrees and children/forks are excluded from OM;
worktrees are never redirected to the primary checkout.

**Upgrading existing settings:** the five obsolete top-level keys under
`extensions.settings.observational_memory` — `storage`, `model`, `observer`,
`reflector`, and `pools` — are accepted and discarded without validating or
migrating their values. They do not prevent registration when `librarian` is
valid. You may remove those blocks as optional cleanup; no settings files are
rewritten automatically. Registration emits one aggregate warning listing only
the ignored key names and explaining that Librarian owns storage and processing.
Repeated settings reads and jobs do not emit migration warnings. Other unknown
keys remain errors, and a valid `librarian.url` is still required. Ignoring old
settings does not restore local storage or processing.

Install/configure the package with the Extensions tab in `hatfield setup` for user-wide
settings, or under project `extensions`. Keep OM in `extensions.enabled` when
replacing that list. See the package README for configuration and cutover gates: do
not switch live memory ownership before Librarian deployment, SQLite-free discovery,
safe import ownership and a passing backup restore drill are verified. Existing
SQLite files are neither opened nor deleted by the thin client.

## Environment variables

| Variable | Role |
|---|---|
| `HATFIELD_CAPTURE_ERRORS` | `1` (default) surface caught errors in TUI; `0` crash for tests |
| `HATFIELD_CWD` | Override project CWD |
| `HATFIELD_CACHE_DIR` | Override cache directory |
| `HATFIELD_LOG_DIR` | Override log directory |
| `HATFIELD_BINARY_PATH` | Subprocess/runtime executable override (tests, custom installs) |
| `HATFIELD_LLM_RAW_STREAM_CAPTURE` | Enable raw stream capture |
| `HATFIELD_WRITE_PROMPT_CACHE_DIAGNOSTICS` | `0` (default) skips append-only structural prompt-cache diagnostics; launch future runs with `HATFIELD_WRITE_PROMPT_CACHE_DIAGNOSTICS=1` to enable privacy-safe `diagnostics/prompt-cache.jsonl` records for `session:cache:inspect` |
| `HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH` | Capture path |
| `HATFIELD_APPROVAL_CHANNEL` | Non-empty capability signal that an approval channel exists (see [approvals.md](approvals.md)) |

## `.hatfield/` policy

Open the Extensions tab in `hatfield setup` to install and configure extensions.
`extensions.enabled` replaces the inherited list when set. In project
`.hatfield/settings.yaml`, use `extensions.project_extensions` to add classes to
the resolved list without copying it. This also works when the project explicitly
replaces `extensions.enabled`. Classes are deduplicated in first-seen order.
User settings reject `extensions.project_extensions`. In project setup,
**Add to project** changes only the additions list; base enablement indicates
whether it is inherited or replaced. Removing an addition does not turn off an
extension enabled in the base list. Fork and named-agent selection stay separate.
Use **F2** to switch between user and project scope. Installation alone does
not enable an extension: use Configure and Save. Extension packages provide
their own Symfony Config definitions.

For an installed Composer extension, select **Uninstall** independently of
**Configure**. Confirm the package name and scope: setup removes its direct Composer
requirement, enablement entries, and extension settings block from that scope.
It leaves the other scope and stored extension data alone. Setup refuses removal
if the package lacks a trusted catalog identity, the scope is ambiguous, or existing settings cannot be safely read. If no `settings.yaml` exists,
uninstall leaves it absent. Uninstall does not load extension code. Disable built-in
extensions through Configure instead. Restart Hatfield after uninstalling.

Turning **Enabled for parent** off also turns that extension's fork and agent selections off in the form. Re-enabling the parent does not restore those selections automatically. Save to persist the choices; existing sessions need a restart to load them.

New extension packages use a direct Composer requirement of `*`, allowing published
releases without Composer's inferred patch-level restriction. Dependency and PHP
requirements still apply. Interactive startup previews updates only when an installed
extension's version or source revision changes, not for dependency-only or constraint-only
refreshes. Composer minimizes dependency changes; dependencies required by an extension
update remain visible in the proposed package table.

An update preview can also propose replacing an old installer-generated `^0.0.<patch>`
extension requirement with `*`. Explicit pins, development requirements and unrelated
library constraints are preserved. The preview displays requirement changes and hashes
of the exact proposed `composer.json` and `composer.lock`; declining changes neither file
nor installed packages. Approval installs that reviewed pair with Composer scripts and
plugins disabled, before extension classes load. Restart Hatfield to check for updates;
`/new` inside a running host does not reload PHP extension code.

- Settings and extension enablement are project/user config.
- Sessions, tmp, cache, logs are runtime and should stay untracked.
- Extensions load from the project `.hatfield/extensions` and user `~/.hatfield/extensions` Composer autoloaders when enabled; project packages win collisions. Task workflow and observational memory document setup and settings in their package-local READMEs. For task workflow, read the installed `ineersa/hatfield-ext-task-workflow` package's `README.md`.
- Built-in docs for the model are selected with `builtin: true` under approved roots; repository-only docs stay unmarked.

## Validation tip

After editing docs or packaging selection:

```bash
castor docs:validate
```

### Optional scheduling extension

The optional Scheduling extension is disabled by default. Open the Extensions tab in `hatfield setup`, select **Scheduling (built in)**, enable it for the parent, save, and start a new session. No Composer installation or scheduling settings are required. Disable it through the same form. Alternatively, add `Ineersa\CodingAgent\Extension\Builtin\Scheduling\SchedulingExtension` to `extensions.enabled` before starting a new session.

While disabled, neither the main-only `schedule` tool nor the `/schedule` command is available; no scheduling sources or dispatch callbacks are registered. Agents must use the current tool catalog to check availability. If `schedule` is absent, explain this prerequisite rather than assuming availability or changing settings without user approval. See [recurring work](background-processes.md#recurring-work-schedule) for usage. Definitions belong to the current live controller and are not restored when Hatfield reopens.

### Hatfield 360 session reporting

In `hatfield setup`, open **Extensions**, select **Hatfield 360 (built in)**,
enable it for the parent in the desired scope, and save. Alternatively, add
`Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension` to your existing
`extensions.enabled` list. No Composer installation is needed.
Start Hatfield through an explicit Hatfield 360 agent launch; the launcher
supplies the Unix socket path and scoped credentials. Do not set them in YAML
or copy them into a shell profile. Wrappers must preserve the launch-supplied
environment and access to the socket.

The extension reports conversation selection, foreground work and correlated
approval or question waits of the interactive session. Linux managed shells can also
attempt registration using the running Hatfield process identity. Outside a
managed launch it does nothing. Windows transport is not supported.

Hatfield 360 can show a completion notice after Hatfield confirms that the
response has been complete for at least five seconds with no active subagents
or pending input. The notice includes the end of the final response. Background scripts are shown
separately. Delivery is best effort: a disconnected app does not block editing,
and missed events are not replayed.
