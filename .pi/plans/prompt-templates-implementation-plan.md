# Prompt Templates Implementation Plan

This plan specifies how to add Pi-style prompt templates to Hatfield/agent-core. It is written for a future implementor with no access to the conversation that produced it.

## 0. Source material and important corrections

Primary Pi source of truth:

- `/home/ineersa/claw/pi-mono/packages/coding-agent/src/core/prompt-templates.ts`
- `/home/ineersa/claw/pi-mono/packages/coding-agent/src/utils/frontmatter.ts`
- `/home/ineersa/claw/pi-mono/packages/coding-agent/docs/prompt-templates.md`
- `/home/ineersa/claw/pi-mono/packages/coding-agent/test/prompt-templates.test.ts`

Current agent-core/Hatfield integration points:

- `src/CodingAgent/Config/AppConfig.php`
- `src/CodingAgent/Config/AppConfigLoader.php`
- `src/CodingAgent/Config/SettingsPathResolver.php`
- `src/CodingAgent/CLI/AgentCommand.php`
- `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php`
- `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`
- `src/Tui/Listener/SubmitListener.php`
- `src/Tui/Command/*`
- `src/Tui/Completion/*`
- `config/services.yaml`
- `config/hatfield.defaults.yaml`
- `depfile.yaml`
- `docs/settings.md`

Corrections this plan intentionally makes from the first generated draft:

1. **Use Pi's settings shape:** the Hatfield settings key is top-level `prompts: []`, not `prompts.paths` and not `prompts.enabled`.
2. **Do not invent built-in templates for MVP:** no `config/prompts/review.md`, `plan.md`, or `summarize.md` should be added unless the user explicitly requests built-in templates later.
3. **Command names are canonicalized to lowercase:** Pi preserves filename case; Hatfield intentionally canonicalizes prompt-template command names to lowercase with `strtolower()` (or equivalent ASCII lowercase) from the filename stem. Lowercase filenames are required/recommended; `Review.md` and `review.md` collide as `review` because the loader derives the same lowercase name from both.
4. **Use Symfony Console attribute style in `AgentCommand`:** this app uses invokable command parameters with `#[Option(...)]`, not `configure()->addOption()`.
5. **Process transport pass-through must be explicit:** parent CLI-only prompt-template options must reach the controller child spawned by `JsonlProcessAgentSessionClient`.
6. **Caught read/YAML errors must be diagnostic local degradation:** Pi silently catches some failures; Hatfield project rules require diagnostics/logging or explicit propagation.

## 1. User-facing contract to implement

Prompt templates are Markdown files that expand into full user prompts. A user invokes a template by typing `/name args`, where `name` is the template filename without `.md`.

### 1.1 Locations mapped from Pi to Hatfield

Pi locations:

- Global: `~/.pi/agent/prompts/*.md`
- Project: `.pi/prompts/*.md`
- Settings: `prompts` array with files or directories
- CLI: `--prompt-template <path>` repeatable
- Packages: `prompts/` directories or `pi.prompts` entries in `package.json`

Hatfield MVP locations:

- Global auto-discovery: `~/.hatfield/prompts/*.md`
- Project auto-discovery: `<cwd>/.hatfield/prompts/*.md`
- Settings paths: top-level `prompts: []` in Hatfield settings YAML
- CLI paths: `--prompt-template <path>` repeatable
- Package manifest support: **not implemented in MVP** because Hatfield has no Pi package manager/resource manifest equivalent yet.
- Extension-provided prompt templates: **not implemented in MVP** unless a future ExtensionApi/resource API is added.

Discovery of `*.md` files inside prompt directories is non-recursive. Subdirectories are ignored unless a subdirectory itself is listed explicitly in `prompts: []` or passed by `--prompt-template`.

### 1.2 Template file format

A prompt template is a Markdown file with optional YAML frontmatter:

```markdown
---
description: Review staged git changes
---
Review the staged changes (`git diff --cached`). $ARGUMENTS

Focus on:
- Bugs and logic errors
- Security issues
- Error handling gaps
```

Fields:

| Field | Required | Behavior |
|---|---:|---|
| `description` | No | Used in autocomplete and `/help`; if absent or empty, use first non-empty body line truncated to 60 characters with `...` if longer. Unknown frontmatter keys (e.g. `argument-hint`) are ignored in MVP. |
| Body/content | Yes in practice | Text after frontmatter. It is the template body expanded and sent to the model. Empty body is allowed but not useful. |

Pi frontmatter parsing behavior to replicate:

- Normalize `\r\n` and `\r` to `\n`.
- Frontmatter exists only if content starts with `---` and has a closing delimiter found by searching for `\n---` after offset 3.
- YAML string is the content between the delimiters; body is everything after closing delimiter, `trim()`ed.
- If no valid frontmatter delimiter exists, frontmatter is empty and body is the normalized original content.
- Invalid YAML should not crash startup. In Hatfield, record a diagnostic and log a structured warning/debug event with path and component, without logging template content.

### 1.3 Command names

Hatfield intentionally canonicalizes prompt-template command names to lowercase.

The loader derives the command name as:

```php
$name = strtolower(basename($filePath, '.md'));
```

This means:

- `review.md` → `/review`
- `my-template.md` → `/my-template`
- `Review.md` and `review.md` collide as `review` (first-wins dedup).

Hatfield's existing `CommandParser` already lowercases slash command names, so this matches the TUI routing behavior without changing `CommandParser`. Hyphenated names like `/model-favourites` work.

Users should use lowercase template filenames (`review.md`, not `Review.md`). Tests must cover hyphenated names because existing Hatfield commands use them.

The runtime expander matches template names against the expansion regex match group 1, which is already lowercase from `CommandParser`/TUI routing, so no additional lowercasing is needed at the expander. For non-TUI invocations (headless, controller, `--prompt`), arguments come from `AgentCommand` which also lowercases via Symfony Console input normalization.

### 1.4 Placeholder syntax

Supported placeholders, exactly as Pi:

| Placeholder | Replacement |
|---|---|
| `$1`, `$2`, ... | Positional args, 1-indexed. Out of range becomes empty string. `$0` becomes empty string. |
| `$@` | All args joined by a single space. |
| `$ARGUMENTS` | Same as `$@`. |
| `${@:N}` | Args from Nth onward; `N` is 1-indexed. `${@:0}` behaves like all args because start below 1 clamps to 1. |
| `${@:N:L}` | `L` args starting from Nth. Length 0 returns empty string. Length past end clamps naturally. |

Substitution order is part of the contract:

1. Positional placeholders (`$1`, `$2`, ...)
2. Slices (`${@:N}`, `${@:N:L}`)
3. `$ARGUMENTS`
4. `$@`

This order prevents `$@` from corrupting slice syntax and prevents replacement values from being scanned again.

No recursive substitution:

- Only the template string is scanned.
- Argument values containing `$1`, `$@`, `$ARGUMENTS`, or `${@:2}` remain literal after being inserted.

Other edge behavior to port from Pi tests:

- `$100` maps to args[99] or empty string.
- `$1.5` replaces only `$1` and leaves `.5` literal.
- `$ARGUMENTS` is case-sensitive.
- `$ARGUMENTS_EXTRA` replaces the `$ARGUMENTS` prefix and leaves `_EXTRA` literal, because Pi uses a global regex replacement for `$ARGUMENTS`.
- Backslash is not an escape character.

### 1.5 Argument parsing

Port Pi's `parseCommandArgs(argsString: string): string[]` exactly:

- Iterate over characters left-to-right.
- Single quotes and double quotes both group whitespace.
- Quote characters are consumed and not included in args.
- Quotes do not nest specially; the current active quote ends only on the same quote character.
- Unquoted whitespace splits args. Pi uses `/\s/`, so spaces, tabs, and newlines split.
- Empty quoted strings produce no argument (`""` and `''` are skipped).
- Backslash is literal and does not escape quotes.
- An unclosed quote consumes the rest of the string into the current arg, and the arg is emitted if non-empty.

### 1.6 Expansion

Port Pi's `expandPromptTemplate(text, templates)` behavior:

```text
if text does not start with "/": return text
match /^\/([^\s]+)(?:\s+([\s\S]*))?$/
if no match: return text
templateName = match[1]
argsString = match[2] ?? ""
find first template where template.name === templateName
if found: parse args and substitute template.content
else: return original text
```

Important:

- The regex allows arguments to contain newlines (`[\s\S]*`).
- Expansion is single-pass. If a template expands to text that starts with `/other`, it is not expanded again.
- If no template matches, the original slash text is passed through unchanged at the runtime boundary. In TUI mode, this only happens for text that reaches runtime; unknown real slash commands are handled locally by `SlashCommandRegistry` before runtime.

**Transcript display:** The runtime/model sees the expanded prompt. The transcript follows normal runtime event projection, so it also shows the expanded prompt. There is no special "show raw `/template args` but send expanded prompt" behavior. This is consistent with Pi's runtime event flow where expanded text is what the model receives and what appears in the transcript.

### 1.7 Loading and collision behavior

MVP load order:

1. If prompt templates are not disabled: global auto-discovery `~/.hatfield/prompts/*.md`
2. If prompt templates are not disabled: project auto-discovery `<cwd>/.hatfield/prompts/*.md`
3. If prompt templates are not disabled: explicit settings paths from top-level `prompts: []`
4. CLI paths from `--prompt-template <path>` always load, even when `--no-prompt-templates` is set

This mirrors Pi's core loader order (`global`, `project`, explicit paths) while adapting to Hatfield's settings system. Pi's full `DefaultResourceLoader` has additional package/resource ordering that Hatfield does not have yet.

Collision behavior:

- Deduplicate by lowercase template name.
- First loaded template wins.
- Later duplicate names are ignored.
- Add a structured diagnostic containing resource type `prompt`, name, winner path, and loser path.
- Log a structured event-style diagnostic without raw template content.
- Collision is not a fatal error.

**Loading is once per process.** Templates are loaded on first access (lazy) and cached for the process lifetime. No reload, file-watch, or cache invalidation mechanism. Restart the process to pick up template changes.

### 1.8 CLI flags

Add these to `bin/console agent` via `AgentCommand`'s invokable `#[Option]` parameters:

| Flag | Type | Behavior |
|---|---|---|
| `--prompt-template <path>` | repeatable | Add an explicit template file or directory for this invocation. |
| `--no-prompt-templates` | boolean | Disable auto-discovery and settings `prompts: []`; CLI `--prompt-template` paths still load. |

Only the long flag `--no-prompt-templates` is implemented. No `-np` shortcut.

The exact Symfony Console attribute syntax should follow existing `AgentCommand` style.

## 2. Current Hatfield architecture facts

### 2.1 Settings

Hatfield settings precedence:

```text
config/hatfield.defaults.yaml
  < ~/.hatfield/settings.yaml
  < <cwd>/.hatfield/settings.yaml
```

`AppConfigLoader::overlayConfig()` semantics:

- Associative arrays are deep-overlaid.
- Lists are replaced wholesale by the higher-priority layer.
- Scalars/null replace lower-priority values.

`AppConfigLoader::PATH_CONFIG` is the registry for path-bearing settings. Any path-bearing settings key must be added there or `~`, `%kernel.project_dir%`, and relative paths will not be resolved.

For the MVP `prompts: []` key:

```yaml
prompts: []
```

Add this path config entry:

```php
'[prompts]' => 'list',
```

Because Hatfield list settings replace rather than append, a project `.hatfield/settings.yaml` `prompts:` list will replace the home `prompts:` list. Auto-discovery still scans both global and project prompt directories. If exact Pi-style additive global+project settings paths are required later, implement a layer-aware settings reader in a follow-up; do not silently special-case list merge in `overlayConfig()` for one key.

### 2.2 TUI command flow

Current flow in `SubmitListener`:

```text
SubmitEvent
  -> $text = $screen->extract()
  -> active question interception
  -> SubmissionRouter::route($text)
       -> CommandParser
       -> SlashCommandRegistry::execute() for slash commands
       -> null for normal prompts
  -> if CommandResult: applyCommandResult()
  -> else: StartRunRequest or UserCommand to AgentSessionClient
```

Current command result variants include `DispatchRuntime`, but `SubmitListener::applyCommandResult()` currently ignores it with a comment saying it is for future tasks.

### 2.3 Slash command registry and autocomplete

`SlashCommandRegistry` owns command metadata, aliases, built-in `/help`, built-in `/clear`, and built-in `/exit`.

Runtime commands such as `/copy` and `/model` are registered by `TuiListenerRegistrar` services. `config/services.yaml` tags all `TuiListenerRegistrar` implementations with `app.tui_listener`, and `InteractiveMode` receives them through `!tagged_iterator app.tui_listener`.

`SlashCommandCompletionProvider` reads `SlashCommandRegistry::allMetadata()` every time suggestions are requested. Therefore a prompt-template registrar that adds metadata to the registry automatically feeds autocomplete and `/help`.

`CommandMetadata` currently has:

```php
public function __construct(
    public string $name,
    public array $aliases = [],
    public string $description = '',
    public string $usage = '',
    public bool $acceptsArguments = false,
) {}
```

`CommandMetadata` is **not modified** in MVP. The `argument-hint` frontmatter field is **not parsed or stored** in MVP — it is ignored. Autocomplete and `/help` display use the `description` field only.

### 2.4 Runtime flow

`AgentSessionClient` is the TUI/runtime boundary.

- `InProcessAgentSessionClient::start(StartRunRequest)` builds system/context/skill messages and finally the user `AgentMessage` from `$request->prompt`.
- `InProcessAgentSessionClient::send($runId, UserCommand)` maps `message`/`steer`/`follow_up` to user `AgentMessage` text.
- `JsonlProcessAgentSessionClient` runs a child `bin/console agent --controller --cwd=<runtimeCwd>` process and sends JSONL commands. The child controller delegates to its own in-process runtime client.

Prompt expansion should happen at the final in-process runtime boundary before `AgentRunner` sees user text. This covers:

- TUI with in-process transport.
- TUI with process/controller transport, if CLI options are passed to the child.
- `--headless` JSONL mode.
- `--controller` mode.
- Initial `--prompt` CLI starts.

### 2.5 Deptrac constraints

Relevant existing boundaries:

- `src/Tui/` must not depend on `AgentCore` or app internals except allowed runtime/config contracts.
- `TuiListener` is allowed to depend on `AppRuntimeContract`, `AppRuntimeProjection`, `AppSession`, `AppConfig`, TUI layers, and Symfony TUI.
- `TuiCommand` has no dependencies and should remain pure.
- Runtime internals in `src/CodingAgent/Runtime/InProcess` can depend on app services and `AgentCore`.

Recommended deptrac design:

- Add a new app layer `AppPromptTemplate` for `src/CodingAgent/PromptTemplate/.*`.
- Put narrow TUI-facing contracts/DTOs in `src/CodingAgent/Runtime/Contract/` so `TuiListener` depends only on `AppRuntimeContract`.
- Allow `AppRuntimeInternals` to depend on `AppPromptTemplate`.
- Allow `AppCli` and `AppRuntimeProcess` to depend on `AppPromptTemplate` only if they need the runtime config class directly; prefer injecting through constructor aliases where possible.

## 3. Target architecture

### 3.1 New files

```text
src/CodingAgent/Config/
  PromptsConfig.php                         # typed config for top-level prompts: []

src/CodingAgent/PromptTemplate/
  LoadedPromptTemplate.php                  # internal value object: name, description, content, filePath
  PromptTemplateDiagnostic.php              # collision/read/yaml diagnostics, no raw content
  PromptTemplateLoadResult.php              # templates + diagnostics
  PromptTemplateArgumentParser.php          # parseCommandArgs()
  PromptTemplateSubstitutor.php             # substituteArgs()
  PromptTemplateFrontmatterParser.php       # YAML frontmatter extraction
  PromptTemplateLoader.php                  # auto dirs + settings + CLI paths, non-recursive loading
  PromptTemplateService.php                 # cached catalog + expander; implements Runtime/Contract interfaces
  PromptTemplatesRuntimeConfig.php          # mutable per-invocation CLI overrides

src/CodingAgent/Runtime/Contract/
  PromptTemplateCommand.php                 # TUI-safe DTO: name, description
  PromptTemplateCatalogInterface.php        # all template commands for TUI registration

src/Tui/Listener/
  PromptTemplateCommandRegistrar.php        # low-priority registrar for virtual template slash commands

tests/CodingAgent/PromptTemplate/
  PromptTemplateArgumentParserTest.php
  PromptTemplateSubstitutorTest.php
  PromptTemplateFrontmatterParserTest.php
  PromptTemplateLoaderTest.php
  PromptTemplateServiceTest.php

tests/CodingAgent/Config/
  PromptsConfigTest.php

tests/CodingAgent/Runtime/InProcess/
  PromptTemplateExpansionInProcessTest.php

tests/CodingAgent/Runtime/Process/
  JsonlProcessPromptTemplateOptionsTest.php

tests/Tui/Listener/
  PromptTemplateCommandRegistrarTest.php
  SubmitListenerDispatchRuntimeTest.php

docs/
  prompt-templates.md
```

### 3.2 Modified files

```text
config/hatfield.defaults.yaml               # add top-level prompts: [] docs/comments
.hatfield/settings.yaml                     # add commented/example top-level prompts: [] if project examples are kept in sync
docs/settings.md                            # document prompts: []
config/services.yaml                        # wire services, aliases, tagged iterator priority method if needed
depfile.yaml                                # add AppPromptTemplate layer/rules
src/CodingAgent/Config/AppConfig.php        # add PromptsConfig property
src/CodingAgent/Config/AppConfigLoader.php  # add '[prompts]' => 'list'
src/CodingAgent/CLI/AgentCommand.php        # add options and populate PromptTemplatesRuntimeConfig
src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php
src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php
src/Tui/Listener/SubmitListener.php         # DispatchRuntime forwarding
```

## 4. Detailed implementation spec

### 4.1 Config: top-level `prompts: []`

Add to `config/hatfield.defaults.yaml`:

```yaml
# ---------------------------------------------------------------------------
# Prompt templates
# ---------------------------------------------------------------------------
# Prompt templates are Markdown files invoked with /name, where name is the
# filename without .md. Auto-discovery scans these non-recursive directories:
#   ~/.hatfield/prompts/*.md
#   <cwd>/.hatfield/prompts/*.md
#
# Add extra template files or directories here. Paths support ~,
# %kernel.project_dir%, and relative paths. Relative paths follow Hatfield's
# existing settings path resolution behavior.
#
# Example:
# prompts:
#   - '.hatfield/team-prompts'
#   - '~/shared/prompts/review.md'
prompts: []
```

Add to `docs/settings.md` a matching section. Keep `.hatfield/settings.yaml` in sync if it carries example settings in this repo.

`PromptsConfig.php`:

```php
namespace Ineersa\CodingAgent\Config;

/**
 * Top-level Hatfield `prompts: []` settings.
 */
final readonly class PromptsConfig
{
    /** @param list<string> $paths */
    public function __construct(
        public array $paths = [],
    ) {
    }

    /** @param mixed $raw */
    public static function fromRaw(mixed $raw): self
    {
        if (!\is_array($raw)) {
            return new self();
        }

        $paths = [];
        foreach ($raw as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $paths[] = $value;
            }
        }

        return new self($paths);
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->prompts;
    }
}
```

`AppConfig::fromContainer()` should call `PromptsConfig::fromRaw($data['prompts'] ?? [])` rather than using Symfony denormalizer for this key, because the YAML shape is a list, not an object.

`AppConfigLoader::PATH_CONFIG`:

```php
private const PATH_CONFIG = [
    // existing entries...
    '[prompts]' => 'list',
];
```

### 4.2 Invocation-scoped runtime config

Create `PromptTemplatesRuntimeConfig` for CLI-only overrides, analogous to how `AgentCommand` mutates `SkillsConfig` before sessions start.

```php
namespace Ineersa\CodingAgent\PromptTemplate;

final class PromptTemplatesRuntimeConfig
{
    /** @var list<string> */
    public array $promptTemplatePaths = [];

    public bool $noPromptTemplates = false;

    /** @return list<string> */
    public function controllerArgs(): array
    {
        $args = [];
        if ($this->noPromptTemplates) {
            $args[] = '--no-prompt-templates';
        }
        foreach ($this->promptTemplatePaths as $path) {
            $args[] = '--prompt-template='.$path;
        }

        return $args;
    }
}
```

`AgentCommand::__invoke()` should add parameters near the skills options:

```php
#[Option(description: 'Load a prompt template file or directory (repeatable)')]
array $promptTemplate = [],

#[Option(description: 'Disable prompt template discovery and settings-loaded templates; --prompt-template paths still load')]
bool $noPromptTemplates = false,
```

Only the long flag `--no-prompt-templates` is implemented. No `-np` shortcut.

Before controller/headless/TUI branching:

```php
$this->promptTemplatesRuntimeConfig->promptTemplatePaths = $promptTemplate;
$this->promptTemplatesRuntimeConfig->noPromptTemplates = $noPromptTemplates;
```

`JsonlProcessAgentSessionClient` should receive `PromptTemplatesRuntimeConfig` and append `controllerArgs()` when spawning the child:

```php
[
    ...$this->runtimeConfig->executableCommand(),
    'agent',
    '--controller',
    '--cwd='.$runtimeCwd,
    ...$this->promptTemplatesRuntimeConfig->controllerArgs(),
]
```

This is required so process transport and controller mode see the same CLI prompt-template paths and disable flag as the parent TUI process.

### 4.3 Loader internals

`LoadedPromptTemplate`:

```php
namespace Ineersa\CodingAgent\PromptTemplate;

final readonly class LoadedPromptTemplate
{
    public function __construct(
        public string $name,
        public string $description,
        public string $content,
        public string $filePath,
    ) {
    }
}
```

`PromptTemplateDiagnostic`:

```php
final readonly class PromptTemplateDiagnostic
{
    public function __construct(
        public string $type,       // collision|read_error|yaml_error|invalid_path
        public string $message,
        public string $path = '',
        public string $name = '',
        public string $winnerPath = '',
        public string $loserPath = '',
    ) {
    }
}
```

`PromptTemplateLoadResult`:

```php
final readonly class PromptTemplateLoadResult
{
    /**
     * @param list<LoadedPromptTemplate>     $templates
     * @param list<PromptTemplateDiagnostic> $diagnostics
     */
    public function __construct(
        public array $templates,
        public array $diagnostics,
    ) {
    }
}
```

`PromptTemplateLoader` dependencies:

- `PromptsConfig $promptsConfig`
- `PromptTemplatesRuntimeConfig $runtimeConfig`
- `SettingsPathResolver $pathResolver` for `getHomeDir()` if needed
- `string $cwd` from `%app.cwd%`
- `LoggerInterface $logger`

Loading algorithm:

```php
public function load(): PromptTemplateLoadResult
{
    $loadedByName = [];
    $diagnostics = [];

    if (!$this->runtimeConfig->noPromptTemplates) {
        $this->loadDirectory($this->homeDir().'/.hatfield/prompts', $loadedByName, $diagnostics);
        $this->loadDirectory(rtrim($this->cwd, '/').'/.hatfield/prompts', $loadedByName, $diagnostics);

        foreach ($this->promptsConfig->paths as $path) {
            $this->loadPath($path, $loadedByName, $diagnostics);
        }
    }

    foreach ($this->runtimeConfig->promptTemplatePaths as $path) {
        // CLI paths are loaded even when noPromptTemplates=true.
        $this->loadPath($path, $loadedByName, $diagnostics);
    }

    return new PromptTemplateLoadResult(array_values($loadedByName), $diagnostics);
}
```

`loadDirectory()` requirements:

- Return with no diagnostic for a missing auto-discovery directory.
- For an explicit settings/CLI path that does not exist, add an `invalid_path` diagnostic.
- Non-recursive scan only.
- Sort entries by filesystem order? Pi uses `readdirSync()` order. For deterministic tests in PHP, sort entries lexically after `scandir()`; document this as a harmless deterministic improvement if chosen.
- Load only files whose filename ends with `.md` exactly, case-sensitive, matching Pi.
- Follow symlinks that resolve to files if practical (`is_file()` already follows symlinks in PHP). Broken symlinks are local degradation with a diagnostic for explicit paths; auto-discovery can skip with debug log.

`loadFile()` requirements:

- Read file content.
- Parse frontmatter.
- Name is `basename($path, '.md')` for exact `.md` suffix.
- Description from frontmatter `description` or first non-empty body line truncated to 60 chars.
- Unknown frontmatter keys are ignored.
- First-wins dedupe by name.
- Never log raw body/content.

All catches must either propagate or record/log local degradation. Example:

```php
try {
    $parsed = $this->frontmatterParser->parse($raw, $path);
} catch (\Throwable $e) {
    $diagnostics[] = new PromptTemplateDiagnostic(
        type: 'yaml_error',
        message: 'Prompt template frontmatter could not be parsed',
        path: $path,
    );
    $this->logger->warning('prompt_template.frontmatter_parse_failed', [
        'component' => 'prompt_template_loader',
        'event_type' => 'prompt_template.frontmatter_parse_failed',
        'path' => $path,
        'exception_class' => $e::class,
    ]);
    // Intentional local degradation: load body with empty frontmatter.
}
```

### 4.4 Parser and substitutor

`PromptTemplateArgumentParser::parse(string $argsString): array` should port Pi's state machine. Use byte iteration if acceptable for quote/whitespace ASCII delimiters; preserve UTF-8 argument content by appending substrings/characters carefully. Simpler MVP can use `preg_split('//u')` or `mb_str_split()` if mbstring is available in the app runtime.

Pseudocode:

```php
/** @return list<string> */
public function parse(string $argsString): array
{
    $args = [];
    $current = '';
    $inQuote = null;

    foreach ($this->characters($argsString) as $char) {
        if (null !== $inQuote) {
            if ($char === $inQuote) {
                $inQuote = null;
            } else {
                $current .= $char;
            }
            continue;
        }

        if ('"' === $char || "'" === $char) {
            $inQuote = $char;
            continue;
        }

        if (1 === preg_match('/\s/u', $char)) {
            if ('' !== $current) {
                $args[] = $current;
                $current = '';
            }
            continue;
        }

        $current .= $char;
    }

    if ('' !== $current) {
        $args[] = $current;
    }

    return $args;
}
```

`PromptTemplateSubstitutor::substitute(string $content, array $args): string`:

```php
$result = preg_replace_callback('/\$(\d+)/', ...);
$result = preg_replace_callback('/\$\{@:(\d+)(?::(\d+))?\}/', ...);
$allArgs = implode(' ', $args);
$result = str_replace('$ARGUMENTS', $allArgs, $result);
$result = str_replace('$@', $allArgs, $result);
return $result;
```

For slices:

```php
$start = ((int) $m[1]) - 1;
if ($start < 0) {
    $start = 0;
}
if (isset($m[2]) && '' !== $m[2]) {
    return implode(' ', array_slice($args, $start, (int) $m[2]));
}
return implode(' ', array_slice($args, $start));
```

### 4.5 Expander service and runtime contracts

Runtime/Contract DTO for TUI:

```php
namespace Ineersa\CodingAgent\Runtime\Contract;

final readonly class PromptTemplateCommand
{
    public function __construct(
        public string $name,
        public string $description,
    ) {}
}
```

Only one contract interface goes in `Runtime/Contract` — the catalog interface needed by TUI for deptrac-safe registration:

```php
interface PromptTemplateCatalogInterface
{
    /** @return list<PromptTemplateCommand> */
    public function allPromptTemplateCommands(): array;
}
```

There is **no** `PromptTemplateExpanderInterface`. The TUI never needs to expand templates. The runtime layer (`InProcessAgentSessionClient`) depends on the concrete `PromptTemplateService` directly. This is deptrac-safe because `AppRuntimeInternals` is allowed to depend on the `AppPromptTemplate` layer.

`PromptTemplateService` implements the catalog interface and also exposes expansion directly:

```php
final class PromptTemplateService implements PromptTemplateCatalogInterface
{
    private ?PromptTemplateLoadResult $cached = null;

    public function __construct(
        private readonly PromptTemplateLoader $loader,
        private readonly PromptTemplateArgumentParser $argumentParser,
        private readonly PromptTemplateSubstitutor $substitutor,
    ) {
    }

    public function allPromptTemplateCommands(): array
    {
        return array_map(
            static fn (LoadedPromptTemplate $t) => new PromptTemplateCommand(
                name: $t->name,
                description: $t->description,
            ),
            $this->result()->templates,
        );
    }

    public function expandPromptTemplate(string $text): string
    {
        if (!str_starts_with($text, '/')) {
            return $text;
        }

        if (1 !== preg_match('#^/([^\s]+)(?:\s+([\s\S]*))?$#', $text, $matches)) {
            return $text;
        }

        $templateName = $matches[1];
        $argsString = $matches[2] ?? '';

        foreach ($this->result()->templates as $template) {
            if ($template->name === $templateName) {
                $args = $this->argumentParser->parse($argsString);

                return $this->substitutor->substitute($template->content, $args);
            }
        }

        return $text;
    }

    private function result(): PromptTemplateLoadResult
    {
        return $this->cached ??= $this->loader->load();
    }
}
```

Runtime expansion notes:

- Do not expand `answer_human` or `answer_tool_question` values; those are structured responses, not user prompts.
- Templates are loaded once per process (lazy on first `allPromptTemplateCommands()` or `expandPromptTemplate()` call).
- The `$template->name` in the expander is lowercase (canonicalized at load time), so the expansion regex group-1 match — which is already lowercase from TUI/CLI routing — matches directly.

### 4.6 TUI virtual commands

`PromptTemplateCommandRegistrar` registers one virtual slash command per template so templates appear in `/help` and slash autocomplete.

Dependencies:

- `SlashCommandRegistry`
- `PromptTemplateCatalogInterface`

Behavior:

- Run after real slash-command registrars.
- For each template command:
  - If `$registry->has($template->name)`, skip it. Real commands win.
  - Register `CommandMetadata` with `name`, `description`, `usage` (`'/name <args>'` for templates that accept arguments), and `acceptsArguments: true`.
  - `CommandMetadata` is **not modified**. No argument-hint from frontmatter is used.
  - Handler returns `new DispatchRuntime($command->originalText)`.

Pseudocode:

```php
final class PromptTemplateCommandRegistrar implements TuiListenerRegistrar
{
    public static function getPriority(): int
    {
        return -100;
    }

    public function __construct(
        private readonly SlashCommandRegistry $registry,
        private readonly PromptTemplateCatalogInterface $catalog,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        foreach ($this->catalog->allPromptTemplateCommands() as $template) {
            if ($this->registry->has($template->name)) {
                continue;
            }

            $this->registry->register(
                new CommandMetadata(
                    name: $template->name,
                    aliases: [],
                    description: $template->description,
                    usage: '/'.$template->name.' <args>',
                    acceptsArguments: true,
                ),
                new class implements SlashCommandHandler {
                    public function handle(SlashCommand $command): CommandResult
                    {
                        return new DispatchRuntime($command->originalText);
                    }
                },
            );
        }
    }
}
```

`/help <template>` displays `Usage: /template-name <args>` because `usage` is set generically.

Autocomplete shows the `description` field. `SlashCommandCompletionProvider` is **not modified**.

Priority wiring options:

1. Preferred: change `InteractiveMode`'s tagged iterator to use a default priority method:

   ```yaml
   Ineersa\Tui\Application\InteractiveMode:
     arguments:
       $listenerRegistrars: !tagged_iterator { tag: app.tui_listener, default_priority_method: getPriority }
   ```

   Then only `PromptTemplateCommandRegistrar::getPriority()` needs to return `-100`; existing registrars default to priority 0.

2. Alternative: manually tag `PromptTemplateCommandRegistrar` with priority. Verify Symfony does not also add an unprioritized `_instanceof` tag that causes duplicate iteration. If it does, use option 1.

### 4.7 `DispatchRuntime` handling in `SubmitListener`

Refactor `SubmitListener` to avoid duplicating the normal prompt dispatch block.

Add a private helper, preserving the existing comments about no local echo and canonical runtime events:

```php
private static function dispatchToRuntime(
    string $text,
    AgentSessionClient $client,
    HatfieldSessionStore $sessionStore,
    TuiSessionState $state,
    ChatScreen $screen,
    TranscriptBlockFactory $blockFactory,
): void {
    try {
        if (null === $state->handle && null === $state->request) {
            $state->request = new StartRunRequest(prompt: $text, runId: $state->sessionId);
            $state->handle = $client->start($state->request);
            $state->activity = RunActivityStateEnum::Starting;
            $sessionStore->updateMetadata($state->sessionId, [
                'run_id' => $state->sessionId,
                'prompt' => $text,
            ]);
            $state->lastSeq = 0;
        } elseif (null !== $state->handle) {
            if ($state->activity->isActive()) {
                $client->send($state->handle->runId, new UserCommand(type: 'steer', text: $text));
            } else {
                $client->send($state->handle->runId, new UserCommand(type: 'follow_up', text: $text));
                $state->activity = RunActivityStateEnum::Starting;
            }
        }
    } catch (\Throwable $e) {
        $state->activity = RunActivityStateEnum::Failed;
        $state->transcript[] = $blockFactory->error(
            runId: $state->sessionId,
            text: 'Runtime error: '.$e->getMessage(),
            seq: \count($state->transcript) + 1,
        );
        $screen->setWorkingMessage('');
        $screen->setTranscriptBlocks($state->transcript);

        return;
    }

    $screen->setWorkingMessage('Working...');
    $screen->setTranscriptBlocks($state->transcript);
}
```

In the submit callback:

```php
$commandResult = $router->route($text);

if ($commandResult instanceof DispatchRuntime) {
    self::dispatchToRuntime($commandResult->payload, $client, $sessionStore, $state, $screen, $blockFactory);

    return;
}

if (null !== $commandResult) {
    self::applyCommandResult(...);

    return;
}

self::dispatchToRuntime($text, $client, $sessionStore, $state, $screen, $blockFactory);
```

This ensures template slash commands use the same start/steer/follow-up routing as normal prompts.

### 4.8 Runtime expansion in `InProcessAgentSessionClient`

Inject the concrete `PromptTemplateService` into `InProcessAgentSessionClient`. This is deptrac-safe: `AppRuntimeInternals` is allowed to depend on `AppPromptTemplate`.

In `start()`:

```php
$prompt = $this->promptTemplateService->expandPromptTemplate($request->prompt);

if ('' !== $prompt) {
    $messages[] = new AgentMessage(
        role: 'user',
        content: [['type' => 'text', 'text' => $prompt]],
    );
}
```

**Transcript display:** The runtime/model sees the expanded prompt. Transcript follows normal runtime event projection, so it also shows the expanded prompt. There is no special "show raw `/template args` but send expanded" behavior. Session metadata stores the expanded prompt as submitted to the model; the raw `/template args` text is not separately preserved unless added in a follow-up.

In `send()`:

```php
$text = $command->text ?? '';
if (\in_array($command->type, ['message', 'steer', 'follow_up'], true)) {
    $text = $this->promptTemplateService->expandPromptTemplate($text);
}

match ($command->type) {
    'steer', 'message' => $this->runner->steer(... $text ...),
    'follow_up' => $this->runner->followUp(... $text ...),
    'answer_human' => ... unchanged ...,
    'answer_tool_question' => ... unchanged ...,
};
```

Expansion happens once at this final in-process boundary. Do not also expand in TUI.

### 4.9 Completion and help

`CommandMetadata` and `SlashCommandCompletionProvider` are **not modified** in MVP.

- `argument-hint` is not parsed or stored from frontmatter.
- The TUI registrar sets `usage` to `'/$name <args>'` generically for templates that accept arguments.
- `/help <template>` shows this generic usage.
- Autocomplete display uses the `description` field.
- `insertText` is `/$name ` as usual.

### 4.10 Services

Add factories/aliases in `config/services.yaml`:

```yaml
Ineersa\CodingAgent\Config\PromptsConfig:
  factory: ['Ineersa\CodingAgent\Config\PromptsConfig', 'fromAppConfig']
  arguments:
    - '@Ineersa\CodingAgent\Config\AppConfig'

Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig: ~

Ineersa\CodingAgent\PromptTemplate\PromptTemplateLoader:
  arguments:
    $promptsConfig: '@Ineersa\CodingAgent\Config\PromptsConfig'
    $runtimeConfig: '@Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig'
    $cwd: '%app.cwd%'

Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCatalogInterface:
  alias: Ineersa\CodingAgent\PromptTemplate\PromptTemplateService
```

The expander has no separate interface. `InProcessAgentSessionClient` injects the concrete `PromptTemplateService` directly.

Most `src/CodingAgent/PromptTemplate/**/*.php` services can be autowired by the existing `Ineersa\CodingAgent\` resource. Only scalar args and aliases need explicit config.

### 4.11 Deptrac

Add layer:

```yaml
- name: AppPromptTemplate
  collectors:
    - type: directory
      value: src/CodingAgent/PromptTemplate/.*
```

Ruleset:

```yaml
AppPromptTemplate:
  - AppConfig
  - AppRuntimeContract
  - SymfonyYaml
  - PsrLog
```

Use the actual existing deptrac layer names for logger/Symfony if they differ. If there is no `PsrLog` layer, add one only if other app layers already use it or follow the repo convention.

Add `AppPromptTemplate` to:

- `AppRuntimeInternals` — `InProcessAgentSessionClient` injects the concrete `PromptTemplateService` directly.
- `AppRuntimeProcess` if `JsonlProcessAgentSessionClient` directly injects `PromptTemplatesRuntimeConfig`
- `AppCli` if `AgentCommand` directly injects `PromptTemplatesRuntimeConfig`

`TuiListener` must not depend on `AppPromptTemplate`. It depends on `PromptTemplateCatalogInterface` from `AppRuntimeContract` (already an allowed dependency for `TuiListener`).

## 5. Tests to write

Use Castor for all test commands.

### 5.1 Parser/substitution tests

Port Pi's `prompt-templates.test.ts` cases.

`PromptTemplateArgumentParserTest`:

- simple args
- double quoted arg
- single quoted arg
- mixed quotes
- empty string
- extra spaces
- tabs
- newlines as separators
- newlines inside quotes preserved
- empty quotes skipped
- special characters
- unicode
- backslash literal, no escaping
- leading/trailing whitespace
- unclosed quote

`PromptTemplateSubstitutorTest`:

- `$ARGUMENTS` and `$@` replacements and equivalence
- no recursive substitution
- positional placeholders
- mixed positional and all args
- empty args
- multiple occurrences
- special chars/unicode/newlines in args
- `$0`, `$100`, `$1.5`
- `$ARGUMENTS` as substring
- case sensitivity
- long arg list
- multi-digit placeholders
- no placeholders passthrough
- slices `${@:N}` and `${@:N:L}` including out of range, zero length, start 0, mixed placeholders

### 5.2 Frontmatter/loader tests

`PromptTemplateFrontmatterParserTest`:

- valid frontmatter + trimmed body
- no frontmatter
- starts with `---` but no closing delimiter
- CRLF normalization
- invalid YAML returns diagnostic/local degradation according to implemented behavior

`PromptTemplateLoaderTest`:

- auto global directory
- auto project directory
- settings `prompts: []` explicit file
- settings `prompts: []` explicit directory
- CLI explicit file/directory
- `--no-prompt-templates` skips auto and settings but still loads CLI paths
- non-recursive directory scanning
- only `.md` exact suffix
- symlinked file if supported
- missing auto dirs are quiet
- missing explicit paths create diagnostics
- description fallback first non-empty line truncated to 60 chars
- unknown frontmatter keys ignored
- first-wins collisions with diagnostics
- no raw content in logs/diagnostics

### 5.3 Config/CLI/process tests

`PromptsConfigTest`:

- `prompts: []` default
- non-string entries ignored or rejected per chosen implementation
- path resolution through `[prompts] => list`
- project list replaces home list per Hatfield semantics

`AgentCommandPromptTemplatesOptionsTest` or existing CLI command test:

- `--prompt-template path` populates runtime config
- repeated `--prompt-template` preserves order
- `--no-prompt-templates` populates runtime config

`JsonlProcessPromptTemplateOptionsTest`:

- process client spawn command includes repeated `--prompt-template=...`
- spawn command includes `--no-prompt-templates`
- no prompt-template args appear when not configured

### 5.4 TUI tests

`PromptTemplateCommandRegistrarTest`:

- registers one command per template with lowercase names
- skips name that already exists in `SlashCommandRegistry` (real commands win)
- returns `DispatchRuntime` with original slash text
- metadata has `acceptsArguments: true`
- metadata usage is generic `'/<name> <args>'`
- command appears in `allMetadata()` and `/help`
- hyphenated names work (lowercase)
- mixed-case template filenames canonicalize to lowercase and collide as same name

No `SlashCommandCompletionProvider` changes or tests needed — `CommandMetadata` is not modified.

`SubmitListenerDispatchRuntimeTest`:

- `DispatchRuntime` starts a new run if no handle exists
- `DispatchRuntime` sends `steer` while active
- `DispatchRuntime` sends `follow_up` while idle and marks activity starting
- runtime error path still adds error block and clears working message

### 5.5 Runtime integration tests

`PromptTemplateExpansionInProcessTest`:

- `start()` expands `/template args` before `AgentRunner::start()`
- `send(... type: steer ...)` expands
- `send(... type: follow_up ...)` expands
- `send(... type: message ...)` expands
- non-template slash text passes through unchanged
- non-slash text passes through unchanged
- answer/human/tool-question commands are not expanded
- expansion happens once only

### 5.6 Optional E2E

Because this touches runtime/TUI-visible flow, full validation must include `castor check` before moving a task to code review. If llama.cpp/tmux prerequisites are unavailable, keep the task IN-PROGRESS and record the blocker.

A focused TUI E2E can be added if stable:

- Create a temp cwd with `.hatfield/prompts/review.md` (lowercase filename).
- Launch TUI with test isolation.
- Type `/review foo`.
- Assert transcript shows the expanded prompt text (runtime event projection is consistent — no special raw display).

## 6. Documentation

Add `docs/prompt-templates.md` adapted from Pi docs:

- What prompt templates are.
- Locations in Hatfield.
- Markdown/frontmatter format.
- Placeholder syntax and examples.
- Loading rules: non-recursive, first-wins collision, CLI disable flag.
- TUI autocomplete and `/help` behavior.
- Limitations: no package manifest support yet, no extension-provided templates yet, lower-case filenames recommended for TUI.

Update `docs/settings.md`:

- Document top-level `prompts: []`.
- Explain Hatfield settings precedence/list replacement.
- Explain auto-discovery dirs are separate from `prompts: []`.
- Explain `--no-prompt-templates` disables auto/settings for that invocation.

## 7. Task breakdown and execution order

Implementation is split into four tracked tasks. The task files in `tasks/TODO/` are the executable breakdown. The phases listed below are the internal ordering within each task.

### Dependency graph

```
PT-01 (core config/parser/loader/catalog)
  │
  ├──▶ PT-02 (runtime, CLI, process transport expansion) ──┐
  │                                                        │
  └──▶ PT-03 (TUI slash commands, SubmitListener dispatch) ─┤
           │                                                │
           └────────▶ PT-04 (docs, E2E smoke, final) ◀─────┘
```

**PT-01 must land first.** It is the shared foundation — parser, substitutor, loader, catalog interface, config, and `PromptTemplateService`. PT-02 and PT-03 both depend on the catalog/service contract from PT-01.

**PT-02 and PT-03 can run in parallel** after PT-01 lands. They touch different integration surfaces:

| Task | Integration surface | Key files |
|---|---|---|
| PT-02 | Runtime/CLI/process transport | `InProcessAgentSessionClient`, `AgentCommand`, `JsonlProcessAgentSessionClient` |
| PT-03 | TUI registrar / SubmitListener | `PromptTemplateCommandRegistrar`, `SubmitListener` |

They share no implementation files and can be forked, tested, and reviewed independently.

**PT-04 depends on both PT-02 and PT-03.** Docs can be drafted in parallel with implementation, but final E2E smoke and full `LLM_MODE=true castor check` require both PT-02 and PT-03 because they jointly exercise the full runtime + TUI integration path.

### Task reference

| Task ID | File | Summary |
|---|---|---|
| PT-01 | `tasks/TODO/prompt-templates-01-core-config-loader.md` | `PromptsConfig`, `PromptTemplatesRuntimeConfig`, `PromptTemplateArgumentParser`, `PromptTemplateSubstitutor`, `PromptTemplateFrontmatterParser`, `PromptTemplateLoader`, `PromptTemplateService`, `PromptTemplateCatalogInterface`, internal DTOs, deptrac `AppPromptTemplate` layer |
| PT-02 | `tasks/TODO/prompt-templates-02-runtime-cli-process-expansion.md` | Expansion in `InProcessAgentSessionClient::start()`/`send()`, `AgentCommand` options, process transport pass-through in `JsonlProcessAgentSessionClient` |
| PT-03 | `tasks/TODO/prompt-templates-03-tui-slash-command-dispatch.md` | `PromptTemplateCommandRegistrar`, `DispatchRuntime` forwarding in `SubmitListener` |
| PT-04 | `tasks/TODO/prompt-templates-04-docs-e2e-validation.md` | `docs/prompt-templates.md`, `docs/settings.md` update, E2E smoke, full `castor check` |

### Internal phase ordering per task

Each task's `tasks/TODO/` file contains its own acceptance criteria and phase list. In summary:

- **PT-01** (4 phases): parser/substitutor → frontmatter/primitive DTOs → config/path resolution → loader + catalog service + deptrac.
- **PT-02** (3 phases): runtime expansion in `InProcessAgentSessionClient` → CLI `AgentCommand` options → process transport pass-through.
- **PT-03** (3 phases): `PromptTemplateCommandRegistrar` → `DispatchRuntime` forwarding in `SubmitListener` → TUI tests.
- **PT-04** (2 phases): docs → E2E smoke + full Castor validation.

### Validation per task boundary

Each task must pass before moving to CODE-REVIEW:

- **PT-01:** `castor test` (parser, substitutor, frontmatter, loader tests), `castor deptrac`, `castor phpstan`, `castor cs-check`.
- **PT-02:** `castor test` (runtime expansion + CLI/process tests), `castor deptrac`, `castor phpstan`, `castor cs-check`. **Because PT-02 touches runtime/LLM-visible flow, `LLM_MODE=true castor check` is mandatory before CODE-REVIEW.** (The workflow gate runs it via `move_task(to="CODE-REVIEW")`.)
- **PT-03:** `castor test` (registrar + SubmitListener dispatch tests), `castor deptrac`, `castor phpstan`, `castor cs-check`. **Because PT-03 touches TUI submission/LLM-visible flow, `LLM_MODE=true castor check` is mandatory before CODE-REVIEW.** (The workflow gate runs it via `move_task(to="CODE-REVIEW")`.)
- **PT-04:** `LLM_MODE=true castor check` (requires tmux + llama.cpp:9052). If prerequisites unavailable, PT-04 must stay IN-PROGRESS.

## 8. Validation commands

Run targeted commands during implementation, always through Castor:

```bash
castor test --filter=PromptTemplateArgumentParserTest
castor test --filter=PromptTemplateSubstitutorTest
castor test --filter=PromptTemplateLoaderTest
castor test --filter=PromptTemplateExpansionInProcessTest
castor test --filter=PromptTemplateCommandRegistrarTest
castor test --filter=SubmitListenerDispatchRuntimeTest
castor deptrac
castor phpstan
castor cs-check
```

Before PR/code review for a task that implements this feature:

```bash
LLM_MODE=true castor check
```

If tmux or llama.cpp on port 9052 is unavailable, do not mark the task CODE-REVIEW. Record the blocker and keep the task IN-PROGRESS.

## 9. Resolved decisions / Deferred follow-ups

These items were considered during planning and are now resolved or deferred:

**Resolved:**

1. **Command name case:** Hatfield canonicalizes template names to lowercase (`strtolower(basename($path, '.md'))`). Lowercase filenames are required; mixed-case filenames collide. No `CommandParser` change needed — it already lowercases slash names.
2. **CLI disable flag:** Only `--no-prompt-templates` (long flag). No `-np` shortcut.
3. **Transcript display:** Runtime/model sees expanded prompt; transcript follows normal runtime event projection showing expanded text. No special raw-display behavior.
4. **Expander interface:** No `PromptTemplateExpanderInterface`. TUI uses catalog interface only; runtime injects concrete `PromptTemplateService`.
5. **CommandMetadata API / argument-hint:** Not modified in MVP. `argument-hint` is not parsed or stored; only `description` and `content` are extracted from frontmatter. `usage` is generic `'/<name> <args>'`. `SlashCommandCompletionProvider` unchanged.
6. **Settings shape:** Only top-level `prompts: []`. No `prompts.paths`, `prompts.enabled`, or compatibility shims.
7. **Diagnostics:** Internal only — diagnostics array + structured logs. No `/templates` command or UI.
8. **Loading:** Once per process, lazy on first access. No reload/watch/cache invalidation.

**Deferred to follow-up (not in MVP scope):**

1. **Package/extension-provided templates.** Requires ExtensionApi resource-loader or package manifest work. No speculative support built now.
2. **Diagnostics UI.** A future `/templates` or config screen could surface collision/read-failure diagnostics.
3. **Pi `argument-hint` field.** Future work can parse `argument-hint` from frontmatter and display it in autocomplete/help, adding `argumentHint` to `CommandMetadata` and updating `SlashCommandCompletionProvider`.
4. **Settings layer-aware additive paths.** If exact Pi-style global+project settings aggregation is needed, implement a layer-aware reader in a follow-up; do not special-case list merge in `overlayConfig()` for one key.
5. **Raw transcript preservation.** If users want the raw `/template args` preserved separately from expanded prompt in session metadata or events, add as a follow-up.

## 10. Non-goals for MVP

- Built-in templates shipped in `config/prompts/`.
- Package manifest support (`pi.prompts`).
- Extension-provided templates.
- Recursive template expansion.
- Conditional/template logic beyond Pi placeholders.
- Template-specific model/reasoning selection.
- `argument-hint` frontmatter field (parsing, storage, display, validation).
- Argument-level autocomplete after the template name.
