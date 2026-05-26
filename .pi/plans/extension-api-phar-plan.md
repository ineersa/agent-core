# Hatfield Extension API and PHAR Loading Plan

## Goal

Design Hatfield extension loading for a PHAR-distributed app while allowing project-local and reusable third-party extensions to register tools and other runtime integrations.

Hatfield should support a Pi-like extension API (`registerTool()`, hooks, append prompt files), but with PHP/PHAR-appropriate dependency isolation and explicit project-local enablement.

## Core model

Hatfield PHAR is location-independent. It may live anywhere, for example:

```text
/usr/local/bin/hatfield            # wrapper/symlink
/opt/hatfield/hatfield.phar        # actual PHAR
```

Project state is resolved from the current working directory unless a future `--cwd` option overrides it:

```text
/home/user/project/
  .hatfield/
    settings.yaml
    extensions/
      composer.json
      composer.lock
      vendor/
        autoload.php
```

When the user runs:

```bash
cd /home/user/project
hatfield
```

Hatfield reads extension configuration and extension autoload files from:

```text
<cwd>/.hatfield/settings.yaml
<cwd>/.hatfield/extensions/vendor/autoload.php
```

The PHAR location is never used as the extension root.

## Trust and isolation model

Extensions are trusted in-process PHP code.

The extension API is the supported contract, not a sandbox. An enabled extension can execute arbitrary PHP with the same filesystem/process permissions as Hatfield. Users must explicitly enable extensions in settings.

PHP-Scoper should be used for dependency isolation, not security:

```text
hatfield.phar first-party app code:
  Ineersa\AgentCore\*
  Ineersa\CodingAgent\*
  Ineersa\Tui\*

public extension API boundary inside first-party app code:
  Ineersa\Hatfield\ExtensionApi\*

scoped PHAR vendor dependencies:
  HatfieldPharVendor\Symfony\*
  HatfieldPharVendor\Composer\*
  ...

project extension Composer env:
  <cwd>/.hatfield/extensions/vendor/*
```

The public API namespace must remain available under `Ineersa\Hatfield\ExtensionApi\*` in the PHAR so extension packages and Hatfield share the same interface/DTO types. PHAR scoping should target vendor dependencies, not this API boundary.

## Extension API namespace inside CodingAgent

Do not split the extension API into a separate Composer package in v1. Keep it inside the monorepo, but isolate it under a dedicated public namespace/path so it can be extracted later without changing extension-facing names.

Initial location:

```text
src/CodingAgent/ExtensionApi/
```

Public namespace from day one:

```php
namespace Ineersa\Hatfield\ExtensionApi;
```

This namespace is a public compatibility surface. It must contain only stable public contracts and value objects. It must not expose Symfony AI, Symfony DI, AgentCore, TUI, or CodingAgent internals.

The monorepo Composer autoload should map this public namespace explicitly even though the files live under `src/CodingAgent/ExtensionApi/`:

```json
{
  "autoload": {
    "psr-4": {
      "Ineersa\\Hatfield\\ExtensionApi\\": "src/CodingAgent/ExtensionApi/"
    }
  }
}
```

Enforcement:

- `AGENTS.md` documents `src/CodingAgent/ExtensionApi/` as a public API boundary.
- `depfile.yaml` contains an `AppExtensionApi` layer with no allowed dependencies on other project layers.
- Extension loader/registry/runtime code may depend on `ExtensionApi`; `ExtensionApi` must never depend back on loader, registry, runtime, tools, settings, PHAR packaging, Symfony AI, or Symfony DI.

Initial API surface:

```php
namespace Ineersa\Hatfield\ExtensionApi;

interface HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void;
}

interface ExtensionApiInterface
{
    public function registerTool(ToolRegistrationDTO $tool): void;

    // Later / optional:
    // public function onBeforeToolCall(...): void;
    // public function onAfterToolCall(...): void;
}

final readonly class ToolRegistrationDTO
{
    public function __construct(
        public string $name,
        public string $description,
        public array $parametersJsonSchema,
        public mixed $handler,
        public ?string $promptSummary = null,
        public array $promptGuidelines = [],
    ) {}
}

// ExtensionApiInterface::registerTool() registers permanent tools.
// Dynamic tools are managed by CodingAgent ToolRegistry dynamic-tool methods
// and AgentCore toolsRef/ToolSetResolverInterface plumbing, not by this initial public extension DTO.
```

Hatfield PHAR includes this namespace unscoped. External extension code can reference these interfaces at runtime because the PHAR autoloader is already registered before extension classes are loaded.

## Future extraction to package

Later, extract `src/CodingAgent/ExtensionApi/` into a standalone Composer package such as `ineersa/hatfield-extension-api`, while preserving the namespace `Ineersa\Hatfield\ExtensionApi`.

Extraction should be straightforward if the boundary stays pure. Recommended package shape after extraction:

```text
hatfield-extension-api/
  composer.json
  src/
    HatfieldExtensionInterface.php
    ExtensionApiInterface.php
    ToolRegistrationDTO.php
```

Package `composer.json`:

```json
{
  "name": "ineersa/hatfield-extension-api",
  "type": "library",
  "require": {
    "php": ">=8.5"
  },
  "autoload": {
    "psr-4": {
      "Ineersa\\Hatfield\\ExtensionApi\\": "src/"
    }
  }
}
```

Possible extraction mechanisms:

- `git subtree split --prefix=src/CodingAgent/ExtensionApi` for a simple history-preserving split;
- `splitsh-lite`/monorepo split automation if this becomes a repeated release workflow similar to Symfony packages;
- a release script that copies the directory into a temporary package tree and rewrites the path to `src/`.

If using a raw subtree split, the split repository root contains the PHP files directly, so its package autoload can temporarily use:

```json
{
  "autoload": {
    "psr-4": {
      "Ineersa\\Hatfield\\ExtensionApi\\": ""
    }
  }
}
```

Prefer the `src/` package layout for the final published package.

After extraction:

- Hatfield monorepo requires `ineersa/hatfield-extension-api` normally.
- `.hatfield/extensions/composer.json` requires `ineersa/hatfield-extension-api` normally.
- Third-party extensions can declare a Composer dependency on the API package instead of relying only on the PHAR autoloader.

## Extraction-safety rules

To keep future extraction cheap, treat `src/CodingAgent/ExtensionApi/` as if it already lived in a separate repository:

- Namespace must stay `Ineersa\Hatfield\ExtensionApi`.
- Only PHP-native dependencies are allowed.
- Do not reference `Ineersa\AgentCore\*`, `Ineersa\CodingAgent\*` outside `ExtensionApi`, or `Ineersa\Tui\*`.
- Do not reference Symfony, Doctrine, Monolog, Symfony AI, DI attributes, Messenger messages, settings providers, runtime DTOs, or tool registry classes.
- Do not add service attributes or rely on container autoconfiguration.
- DTOs should use primitives, arrays, enums, interfaces, and simple value objects defined inside `ExtensionApi`.
- Handlers exposed through the API should be described by extension-owned interfaces or callables, not by internal Hatfield service types.
- No production code outside `ExtensionApi` may be moved into `ExtensionApi` merely for reuse; if the API needs a concept, define the smallest public contract for it.
- Any breaking change in `ExtensionApi` should be treated as a future package semver decision, even before the package is split.

Enforcement in this repository:

- `AGENTS.md` records these rules for agents and humans.
- `depfile.yaml` defines `AppExtensionApi` over `src/CodingAgent/ExtensionApi/.*`.
- `AppExtensionApi: ~` means the API layer has no allowed dependencies on other project layers.
- `castor deptrac` must remain clean after adding or changing Extension API code.

## Project extension Composer environment

When Hatfield initializes `.hatfield/`, it should also create `.hatfield/extensions/` with a minimal Composer project:

```text
.hatfield/extensions/composer.json
.hatfield/extensions/src/
```

Default `.hatfield/extensions/composer.json`:

```json
{
  "require": {
    "php": ">=8.5"
  },
  "autoload": {
    "psr-4": {
      "HatfieldProject\\Extensions\\": "src/"
    }
  }
}
```

This supports local project extensions immediately. Reusable Composer extension packages can also be installed here, but until `Ineersa\Hatfield\ExtensionApi` is extracted into its own package they cannot express a normal Composer dependency on the API package; they rely on Hatfield providing the API namespace at runtime.

## Settings

Project `.hatfield/settings.yaml` should explicitly enable extensions:

```yaml
extensions:
  enabled:
    - HatfieldProject\Extensions\MyLocalExtension
    - Acme\HatfieldTaskWorkflow\TaskWorkflowExtension
```

Optional future shape for per-extension config:

```yaml
extensions:
  enabled:
    - Acme\HatfieldTaskWorkflow\TaskWorkflowExtension

  config:
    Acme\HatfieldTaskWorkflow\TaskWorkflowExtension:
      task_root: tasks
```

## Boot flow

1. PHAR boots from its installed location.
2. Determine project cwd:
   - default: `getcwd()`;
   - future: `--cwd=/path/to/project`.
3. Load Hatfield internal scoped autoloader.
4. Keep `Ineersa\Hatfield\ExtensionApi\*` unscoped and available through the PHAR autoloader.
5. Read merged Hatfield settings.
6. If present, require:

   ```text
   <cwd>/.hatfield/extensions/vendor/autoload.php
   ```

7. For each class in `extensions.enabled`:
   - verify class exists;
   - instantiate through a small extension factory;
   - require it implements `HatfieldExtensionInterface` or is an accepted callable adapter;
   - call `register($extensionApi)`.
8. `ExtensionApiInterface::registerTool()` submits tool registrations into the CodingAgent-owned `ToolRegistry`.
9. `ToolRegistry` decides prompt visibility, active tool sets, provider schema exposure, and execution allowlist.

## Installing reusable extensions

Reusable extension packages are installed into the project-local extension Composer environment:

```bash
cd /home/user/project/.hatfield/extensions
composer require acme/hatfield-task-workflow
```

Then enable the extension in project settings:

```yaml
extensions:
  enabled:
    - Acme\HatfieldTaskWorkflow\TaskWorkflowExtension
```

This avoids loading the project root `vendor/autoload.php` by default and prevents normal application dependencies from colliding with Hatfield internals.

## Relationship to tool registry

Extension loading and tool registration are separate layers:

- Extension loader discovers and invokes enabled extension classes.
- Extension API provides stable methods such as `registerTool()`.
- Tool registry owns all registered tool candidates and policy:
  - built-in tools;
  - project/local extension tools;
  - third-party Composer extension tools;
  - future dynamic/MCP tools.

Third-party registration means “available to the registry,” not “automatically callable by the model.”

The registry still owns:

- permanent tools shown in the stable system prompt;
- dynamic tools not shown in the stable system prompt;
- request/turn active tool set;
- provider schema tools;
- execution allowlist;
- before/after tool-call hooks.

## Comparison with Pi

Pi extensions are also trusted in-process code. Pi provides an `ExtensionAPI`, but extension code can import other exported internals from the Pi package. Hatfield should treat `Ineersa\Hatfield\ExtensionApi` as the supported contract, while Deptrac and PHAR scoping reduce accidental coupling and dependency conflicts.

Do not present in-process extensions as sandboxed.

## Open decisions

- Exact future package name if `Ineersa\Hatfield\ExtensionApi` is extracted into a standalone Composer package; current candidate is `ineersa/hatfield-extension-api`.
- Whether local extension files returning callables are supported in addition to class-based Composer extensions.
- Whether Hatfield should provide a command such as `hatfield extension:init` or create `.hatfield/extensions/composer.json` during general project init.
- Whether to support global user extensions under `~/.hatfield/extensions` in addition to project-local extensions.
- Whether extension config should be passed through `ExtensionApiInterface`, constructor injection, or a dedicated `ExtensionContextDTO`.

## Tracked v1 tasks, order, and parallelism

Use three implementation tasks for v1. They intentionally keep extension support small while preserving a clean extraction path.

1. **EXT-00: Extension API contracts and boundary**
   - Create `src/CodingAgent/ExtensionApi/` contracts under namespace `Ineersa\Hatfield\ExtensionApi`.
   - Add Composer autoload mapping for `Ineersa\Hatfield\ExtensionApi\`.
   - Keep Deptrac/AGENTS boundary rules green.
   - This is the only hard prerequisite for the other extension tasks.

2. **EXT-01: Project extension loader and settings**
   - Depends on EXT-00.
   - Add `extensions.enabled` settings shape.
   - Load `<cwd>/.hatfield/extensions/vendor/autoload.php` when present.
   - Instantiate enabled `HatfieldExtensionInterface` classes and call `register($api)`.
   - May include `.hatfield/extensions/composer.json` template/init if simple; otherwise leave init convenience for later.

3. **EXT-02: Tool registry bridge**
   - Depends on EXT-00 and TOOLS-R00 from `.pi/plans/toolbox-design-plan.md`.
   - Implement `ExtensionApiInterface::registerTool()` adapter into the CodingAgent `ToolRegistry`.
   - Ensure registered extension tools become permanent ToolRegistry entries and still flow through registry policy: active tool set, provider schema exposure, execution allowlist, prompt summary/guideline dedupe, and hooks.
   - TOOLS-R02 introduces tool definitions and the built-in registrar. TOOLS-R03 is the follow-up that makes registry-only extension handlers executable through a registry-backed Symfony Toolbox.

### Parallelization

- EXT-00 must land before EXT-01 and EXT-02.
- TOOLS-R00 can land independently of the extension loader/API work and is the hard prerequisite for EXT-02.
- After EXT-00, EXT-01 can proceed.
- After EXT-00 + TOOLS-R00, EXT-02 can proceed.
- EXT-01 and EXT-02 can still proceed mostly in parallel once their prerequisites are met:
  - EXT-01 owns settings, project autoload loading, extension class instantiation, and lifecycle errors.
  - EXT-02 owns tool-registration mapping into the registry and execution policy integration.
- Final callable-tool integration requires EXT-01, EXT-02, TOOLS-R00, TOOLS-R02, and TOOLS-R03 together: load a test extension from `.hatfield/extensions`, call `registerTool()`, and verify the tool appears in the registry/active schema path and can execute through the registry-backed Toolbox according to registry policy.

### Later backlog, not v1 tracked tasks

- PHAR build/scoper rules for keeping `Ineersa\Hatfield\ExtensionApi\*` available and vendor deps scoped.
- Dedicated `hatfield extension:init` command.
- Per-extension config object or context DTO.
- Global user extensions under `~/.hatfield/extensions`.
- Standalone `ineersa/hatfield-extension-api` repository/package extraction automation.
- Marketplace/autodiscovery.
- Out-of-process MCP/JSON-RPC extension protocol.
- Extension docs beyond the minimal trusted-code warning in v1.

## Non-goals for v1

- No extension marketplace.
- No automatic Composer package discovery.
- No loading project root `vendor/autoload.php` by default.
- No sandbox/security boundary for in-process extensions.
- No per-extension dependency isolation beyond the shared `.hatfield/extensions/vendor` Composer environment.
- No MCP/JSON-RPC out-of-process extension protocol yet.
