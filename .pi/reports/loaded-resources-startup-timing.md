# Loaded resources startup timing (issue #221 follow-up)

## Parent manual pty smoke (HEAD a2c9b55ba, pre-boundary fix)

- First byte avg ~818ms over 5 runs
- Loaded block visible avg ~842ms
- Theme self-conflicts (`⚠ nord` same path) absent after a2c9b55ba

## Architectural latency model (post a2c9b55ba)

1. **Pre-TUI event loop**: `LoadedResourcesSummaryBuilder::build()` is **not** called synchronously in `InteractiveMode` before `Tui::run()`. The registrar attaches a first-tick handler only.
2. **First tick**: summary build runs once on fresh sessions (`LoadedResourcesStartupRegistrar`), then `requestRender()`.
3. **Resume**: tick handler skips build when `$state->resuming` is true.

## Local micro-benchmark (worktree, non-CI)

Measure isolated summary build cost (kernel boot + one `build()`), not full TUI:

```bash
cd <worktree>
/usr/bin/time -f '%e sec' php -r '
require "vendor/autoload.php";
$k = new Ineersa\CodingAgent\Kernel("test", false);
$k->boot();
$c = $k->getContainer();
$b = $c->get(Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryProviderInterface::class);
$t0 = hrtime(true);
$b->build();
echo "build_ms=" . ((hrtime(true)-$t0)/1e6) . PHP_EOL;
'
```

Full interactive startup (~0.8s first byte per parent) is dominated by Symfony kernel/container, DB/session init, theme/extension discovery during normal boot — not by deferring summary to first tick.

## Sub-0.5s target

Not achieved for **full** `agent` TUI cold start in this environment. Loaded-resources work is deferred to first tick; removing sync pre-loop build was the cheap win. Further sub-500ms would require broader boot optimization (out of issue #221 scope).
## Automated proof (this follow-up)

- `LoadedResourcesStartupRegistrarTest::buildIsNotInvokedBeforeFirstTick` asserts `LoadedResourcesSummaryProviderInterface::build()` is **not** called during `register()` — only after first `TickEvent`.
- Isolated `build()` wall time via public container is not used (services are private); regression guard is deferral contract + parent pty numbers.

## This follow-up commit

- Restores deptrac boundary: `TuiListener` / `TuiApplication` no longer allow `AppLoadedResources`.
- `LoadedResourcesStartupRegistrar` injects `LoadedResourcesSummaryProviderInterface` only.
