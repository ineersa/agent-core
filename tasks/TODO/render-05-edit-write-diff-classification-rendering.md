# RENDER-05: Edit/write ToolResult diff classification and rendering

## Goal
Part of .pi/plans/tui-rich-transcript-blocks-plan.md.

Order: depends on RENDER-01 and RENDER-02 for integration; final rendering depends on RENDER-04 tool result card pipeline. Classifier/service exploration can run in parallel with RENDER-03/RENDER-04 after RENDER-01.

Scope:
- Classify diffs as a rendering mode of existing `ToolResult` blocks, not as a new transcript block kind.
- Limit v1 diff classification to edit/write tool outputs.
- Add a dedicated diff rendering service/widget for diff-classified tool results.
- Use existing theme diff tokens where possible.
- Apply `tui.transcript.previews.diff_lines` when preview mode is collapsed.
- Respect `TranscriptDisplayState::previewableBlocksExpanded` for diff-rendered results.

Parallelism: classifier/service work can start after RENDER-01 and RENDER-02, but final integration is best after or alongside RENDER-04.

## Acceptance criteria
- No new `TranscriptBlockKindEnum::Diff` is added for v1.
- Edit/write tool results can be identified for diff rendering using existing block/meta data or documented metadata additions.
- Diff-rendered tool results use dedicated rendering logic with added/removed/context coloring.
- Long diffs are previewed to `diff_lines` when preview mode is collapsed.
- Diff-rendered tool results expand when `previewableBlocksExpanded=true`.
- Non edit/write tool results continue through the normal tool result rendering path.
- Focused Castor validation is reported for diff classification/rendering behavior.

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
- Created: 2026-05-22T19:09:05.491Z
