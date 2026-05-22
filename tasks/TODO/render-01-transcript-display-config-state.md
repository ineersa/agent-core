# RENDER-01: Transcript display config, mapper, and live state foundation

## Goal
Part of .pi/plans/tui-rich-transcript-blocks-plan.md.

Order: first/root task. Blocks RENDER-02, RENDER-03, RENDER-04, RENDER-05 final integration, and RENDER-06 final behavior.

Scope:
- Add Hatfield config DTO `Ineersa\CodingAgent\Config\TuiTranscriptConfig`.
- Add defaults under `tui.transcript`.
- Add TUI-local `Ineersa\Tui\Transcript\TranscriptDisplayConfig`.
- Add mapper/adapter at the TUI application boundary; keep `Tui\Transcript` independent from `CodingAgent\Config`.
- Add session-only `TranscriptDisplayState` with `previewableBlocksExpanded` initialized from config.
- Stop treating projection `TranscriptBlock::collapsed` as thinking display policy; projection should not set thinking display defaults.
- Update depfile to allow Symfony TUI in TuiTranscript if needed by follow-up renderer work.

Parallelism: none before this task. After this lands, RENDER-02 and RENDER-06 can start; RENDER-05 classifier work may start if it does not touch final renderer integration.

## Acceptance criteria
- `tui.transcript.thinking.visible`, `tui.transcript.thinking.style`, `tui.transcript.previews.expanded_by_default`, `tui.transcript.previews.tool_result_lines`, and `tui.transcript.previews.diff_lines` are parsed with documented defaults.
- `TranscriptDisplayConfig` and `TranscriptDisplayState` exist under `src/Tui/Transcript/` with explicit semantic suffixes.
- Interactive TUI startup maps Hatfield config to TUI display config/state without `Tui\Transcript` depending on `CodingAgent\Config`.
- `TuiSessionState` exposes session-only preview expansion state initialized from `previews.expanded_by_default`.
- Thinking `collapsed` hardcoding is removed or no longer used as display policy.
- `.hatfield/settings.yaml` and `docs/settings.md` document the new keys.
- Focused Castor validation is reported, including `castor phpstan` for touched paths and focused tests.

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
- Created: 2026-05-22T19:08:35.427Z
