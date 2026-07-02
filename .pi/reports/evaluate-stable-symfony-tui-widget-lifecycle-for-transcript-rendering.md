# Evaluate stable Symfony TUI widget lifecycle for transcript rendering

Date: 2026-07-02
Task: `evaluate-stable-symfony-tui-widget-lifecycle-for-transcript-rendering`
Decision: **Defer migration; keep the current rendered-line cache for now.**

## Summary

The task evaluated whether transcript rendering should move from Hatfield's current project-native `TuiWidget` + `string[]` lifecycle with a per-block rendered-line cache to stable Symfony TUI widget instances keyed by `TranscriptBlock::id`.

A small prototype would be technically feasible, but the expected benefit is not compelling enough right now. The current RENDER-05 cache already avoids rebuilding/rendering finalized unchanged blocks. A stable Symfony widget tree would reduce some transient object churn, but it would introduce new lifecycle complexity around block identity, suppression, tool-result pairing, preview state, theme refresh, and neighbor-aware invalidation.

## Current architecture

Relevant files:

- `src/Tui/Transcript/TranscriptBlockWidget.php`
- `src/Tui/Transcript/TranscriptBlockWidgetFactory.php`
- `src/Tui/Transcript/SymfonyTuiWidgetRenderer.php`
- `src/Tui/Widget/LiveTextWidget.php`
- `src/Tui/Screen/ChatScreen.php`
- `src/CodingAgent/Runtime/Projection/TranscriptBlock.php`

Current flow:

1. Runtime projection produces immutable `TranscriptBlock[]`.
2. `ChatScreen::setTranscriptBlocks()` updates `TranscriptBlockWidget` and invalidates the transcript `LiveTextWidget`.
3. `TranscriptBlockWidget::render()` walks visible blocks.
4. Unchanged blocks hit `TranscriptBlockWidget::$blockRenderCache` using a fingerprint of block content, meta, streaming state, terminal dimensions, theme, display config/state, and matched tool result.
5. Cache misses build temporary Symfony `ContainerWidget` / `TextWidget` / `MarkdownWidget` trees through `TranscriptBlockWidgetFactory` and render them via `SymfonyTuiWidgetRenderer`.

The custom cache is project-level, but it is already per-block and avoids most expensive work for finalized unchanged blocks.

## Stable Symfony widget lifecycle feasibility

Local Symfony TUI APIs support the basic shape:

- `AbstractWidget` caches rendered output by render revision, columns, and rows.
- `AbstractWidget::invalidate()` bumps revision and cascades to parents.
- `TextWidget::setText()` mutates content and invalidates.
- `MarkdownWidget::setText()` sanitizes/mutates content and invalidates.
- `ContainerWidget::add()`, `remove()`, and `clear()` manage children and invalidation.

So a stable widget approach is possible: keep a persistent container, maintain child widgets keyed by block id, update changed widgets, and let Symfony cache unchanged widgets.

## Expected wins

Potential benefits:

- Less transient widget allocation on repeated renders.
- More idiomatic Symfony TUI widget lifecycle.
- Possible removal or simplification of custom cache fingerprinting.
- Future-friendly if transcript blocks become interactive widgets rather than rendered lines.

## Expected costs / risks

Costs and risks outweigh benefits for now:

- Current RENDER-05 rendered-line cache already gives the main performance win.
- Stable widgets still require container traversal/layout and child cache checks.
- Parent invalidation cascades; unchanged children may cache-hit, but layout still has to be considered.
- Tool call/result pairing needs composite widget lifecycle keyed by both call and result blocks.
- Empty assistant placeholder suppression depends on neighboring blocks, so updates may invalidate more than one block.
- Hidden/suppressed blocks, preview expansion, thinking visibility, terminal resize, and theme changes all need explicit lifecycle rules.
- Symfony TUI is marked experimental, so deeper reliance on lifecycle/cache internals increases upgrade risk.
- Long transcripts would retain many widget objects and cached line arrays in memory.

## Decision

Do **not** migrate transcript rendering to stable Symfony per-block widgets at this time.

Keep the current RENDER-05 rendered-line cache because it is simple, targeted, and already aligned with the existing `ChatScreen` / `LiveTextWidget` / `string[]` architecture.

A future spike may be worthwhile only if transcript rendering remains a demonstrated hotspot after profiling real long conversations, or if upcoming features require transcript blocks to become interactive/live Symfony widgets.

## If revisited later

Recommended spike boundaries:

1. Implement an alternate renderer behind the same `TranscriptBlock[] -> list<string>` contract.
2. Preserve current glyph/prefix output exactly.
3. Cover user, assistant markdown, thinking, tool exchange, and question blocks.
4. Measure long transcript rendering with unchanged finalized blocks plus a streaming tail.
5. Compare memory use and render time against the current rendered-line cache.
6. Avoid replacing production rendering unless the spike shows a clear performance or maintenance win.

Suggested proof if revisited:

- `castor test --filter=TranscriptBlockRendererTest`
- `castor test --filter=TuiTranscriptBlocksVirtualRenderTest`
- `castor test:tui --filter=TuiTranscriptRenderE2eTest`

Full CODE-REVIEW gate for any future TUI implementation would still require `castor test:tui` and `castor check`.
