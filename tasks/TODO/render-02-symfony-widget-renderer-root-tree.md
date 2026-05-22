# RENDER-02: Symfony TUI widget renderer adapter and root transcript tree

## Goal
Part of .pi/plans/tui-rich-transcript-blocks-plan.md.

Order: depends on RENDER-01.

Scope:
- Add `SymfonyTuiWidgetRenderer` as the adapter isolating direct Symfony TUI `Renderer` usage.
- Change `TranscriptBlockWidget::render()` to build one root `ContainerWidget` for the whole transcript and render it once through the adapter.
- Add initial `TranscriptBlockCardFactory`/equivalent scaffold using Symfony TUI primitives.
- Keep `ChatScreen -> LiveTextWidget -> TuiWidget::render(): string[]` unchanged.
- Preserve existing transcript coverage while making the rendering pipeline Symfony-widget based.

Parallelism: after RENDER-01, this can run in parallel with early RENDER-06 listener work. RENDER-03 and RENDER-04 depend on this adapter/root-tree foundation.

## Acceptance criteria
- `SymfonyTuiWidgetRenderer` renders a root `ContainerWidget` through Symfony `Renderer`; no direct leaf-widget render calls are used as the normal path.
- `TranscriptBlockWidget::render()` renders the whole transcript with one root Symfony widget tree per render.
- Symfony TUI experimental API exposure is localized to adapter/factory code under `src/Tui/Transcript/`.
- `ChatScreen` and `LiveTextWidget` bridge remain structurally unchanged.
- Existing transcript block kinds still render in a readable form, even before richer per-kind cards land.
- Focused Castor validation is reported, including tests/static analysis for touched TUI transcript paths.

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
- Created: 2026-05-22T19:08:42.951Z
