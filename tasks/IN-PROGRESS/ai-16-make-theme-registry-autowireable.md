# AI-16 Make ThemeRegistry autowireable, absorb ThemeLoader

## Goal
## Goal

Consolidate theme loading into `ThemeRegistry`, make it autowireable, and simplify `ThemeFactory` to a thin wrapper.

## Current mess

```
ThemeFactory::buildTheme($name, $paths):
  $loader = new ThemeLoader()           ← manual construction
  // load user paths
  // load built-in path
  // deduplicate by name
  $registry = new ThemeRegistry(...)     ← manual construction
  return new DefaultTheme($registry->getOrThrow($name))
```

## Target

```
ThemeRegistry::__construct(AppConfig, AppResourceLocator):
  // self-loads all palettes from AppConfig->tui->themePaths + built-in path
  // deduplicates (user paths win)
  // stores in internal map

ThemeFactory::__construct(ThemeRegistry):
  // that's it

ThemeFactory::create(?TuiTheme $hint = null): TuiTheme:
  if $hint → return $hint
  $name = $this->appConfig->tui->theme   ← or access via registry
  return new DefaultTheme($this->registry->getOrThrow($name))
```

## Changes

### 1. Move `ThemeLoader` logic into `ThemeRegistry`

- Move `loadFile()` and `loadDirectory()` as private methods into `ThemeRegistry`
- Delete `src/Tui/Theme/ThemeLoader.php`

### 2. `ThemeRegistry` becomes autowireable

```php
final class ThemeRegistry
{
    private array $themes = [];

    public function __construct(
        AppConfig $appConfig,            // after AI-15 — autowireable
        AppResourceLocator $resources,   // already autowireable
    ) {
        $tuiConfig = $appConfig->tui;
        // Load user paths (AppConfig->tui->themePaths)
        // Load built-in path (AppResourceLocator->getBuiltinThemesPath())
        // Deduplicate: user paths override built-in
    }
}
```

**Architecture note:** `ThemeRegistry` in `Ineersa\Tui\Theme` depends on `Ineersa\CodingAgent\Config\AppConfig`. This is acceptable because `ThemeFactory` already does this. If we want to avoid the cross-layer dep, pass `TuiConfig` + theme paths instead of full `AppConfig`. Keep it pragmatic — `AppConfig` is the single source of truth.

### 3. Simplify `ThemeFactory`

```php
final class ThemeFactory
{
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly ThemeRegistry $registry,  // autowired
    ) {}

    public function create(?TuiTheme $hint = null): TuiTheme
    {
        if (null !== $hint) {
            return $hint;
        }
        return new DefaultTheme($this->registry->getOrThrow($this->appConfig->tui->theme));
    }
}
```

- **Delete** `buildTheme()` method
- **Delete** `$resources` dependency (registry handles it)
- **Drop** `$cwd` param (done by AI-15)

### 4. Update callers

- `InteractiveMode::run()` — inject `ThemeFactory`, call `$this->themeFactory->create($theme)` (no cwd)
- Any tests constructing `ThemeFactory`

### 5. Update tests

- `ThemeFactoryTest` — update constructor, remove `$resources`
- `ThemeRegistryTest` — update constructor to take `AppConfig` + `AppResourceLocator`
- `ThemeLoader` tests — move to `ThemeRegistry` test or delete

### 6. DI

- `ThemeRegistry` auto-discovered via `Ineersa\Tui\` namespace autowiring
- `ThemeFactory` auto-discovered (same)
- Remove any explicit `ThemeLoader` wiring if it exists

### 7. Inject `LockFactory` into `HatfieldSessionStore`

Currently `HatfieldSessionStore` manually constructs `new LockFactory(new FlockStore())` in its constructor. Both are autowireable Symfony components — inject `LockFactory` via constructor.

```php
// Before
$this->lockFactory = new LockFactory(new FlockStore());

// After
public function __construct(
    ...,
    private readonly LockFactory $lockFactory,
) {}
```

Remove the inline `new LockFactory(new FlockStore())`.

### 8. Inject `EventPayloadNormalizer` into stores

`RunLogWriter`, `RunLogReader`, and `SessionRunEventStore` currently use `new EventPayloadNormalizer()` as constructor default values. Inject it via autowiring — all three are in the `Ineersa\AgentCore\` namespace and `EventPayloadNormalizer` has a zero-arg constructor.

```php
// Before (default property value)
private readonly EventPayloadNormalizer $eventPayloadNormalizer = new EventPayloadNormalizer(),

// or nullable fallback
$this->eventPayloadNormalizer = $eventPayloadNormalizer ?? new EventPayloadNormalizer();

// After (constructor injection)
public function __construct(
    ...,
    private readonly EventPayloadNormalizer $eventPayloadNormalizer,
) {}
```

### 9. Inject `ToolExecutionResultStore` into `ToolExecutor`

`ToolExecutor` uses nullable fallback: `$resultStore ?? new ToolExecutionResultStore()`. Inject it. `ToolExecutionResultStore` has a zero-arg constructor and is in `Ineersa\AgentCore\` namespace.

### 10. Inject `EventFactory` and `ToolCallExtractor` into `RunMessageStateTools`

Both use default property values with `new`. Inject them via constructor — both have zero-arg constructors.

```php
// Before
private EventFactory $eventFactory = new EventFactory(),
private ToolCallExtractor $toolCallExtractor = new ToolCallExtractor(),

// After
public function __construct(
    ...,
    private readonly EventFactory $eventFactory,
    private readonly ToolCallExtractor $toolCallExtractor,
) {}
```

## Depends on

- AI-15 (AppConfig must be autowireable first)

## Acceptance criteria
- ThemeLoader class deleted, logic moved into ThemeRegistry
- ThemeRegistry is autowireable — loads palettes from AppConfig + built-in path
- ThemeFactory simplified — injects ThemeRegistry, no buildTheme(), no resources dep
- HatfieldSessionStore injects LockFactory instead of manual construction
- EventPayloadNormalizer injected into RunLogWriter, RunLogReader, SessionRunEventStore
- ToolExecutionResultStore injected into ToolExecutor
- EventFactory and ToolCallExtractor injected into RunMessageStateTools
- castor check green

## Workflow metadata
Status: IN-PROGRESS
Branch: task/ai-16-make-theme-registry-autowireable
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-16-make-theme-registry-autowireable
Fork run: 0ckd60jp2uwo
PR URL:
PR Status:
Started: 2026-05-17T20:48:08.230Z
Completed:

## Work log
- Created: 2026-05-17T04:19:51.135Z

## Task workflow update - 2026-05-17T20:48:08.230Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-16-make-theme-registry-autowireable.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-16-make-theme-registry-autowireable.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-16-make-theme-registry-autowireable.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-16-make-theme-registry-autowireable.

## Task workflow update - 2026-05-17T20:48:34.113Z
- Recorded fork run: 0ckd60jp2uwo
- Summary: Started AI-16 implementation in background fork 0ckd60jp2uwo. Scope: ThemeRegistry absorbs ThemeLoader and becomes autowireable from AppConfig/AppResourceLocator, ThemeFactory simplified, inject LockFactory into HatfieldSessionStore, inject EventPayloadNormalizer into run event stores, inject ToolExecutionResultStore into ToolExecutor, inject EventFactory/ToolCallExtractor into RunMessageStateTools, update tests/DI, validate with Castor. Fork instructed to work only inside /home/ineersa/projects/agent-core-worktrees/ai-16-make-theme-registry-autowireable and verify main untouched.
