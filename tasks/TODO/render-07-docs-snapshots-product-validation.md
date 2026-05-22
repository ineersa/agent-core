# RENDER-07: Docs, snapshots, and product-level rich transcript validation

## Goal
Part of .pi/plans/tui-rich-transcript-blocks-plan.md.

Order: final integration task. Depends on RENDER-01 through RENDER-06.

Scope:
- Update docs for final transcript rendering behavior and keybindings.
- Add/refresh TUI snapshots as needed.
- Run required product-level Castor validation for TUI runtime/transcript rendering work.
- Create or update a real agent/real LLM validation test for rich transcript rendering, at minimum covering assistant messages and thinking blocks.
- Capture and report session artifacts on failure.
- Polish density/visual issues found in snapshots without changing the core architecture.

Parallelism: final task only. Do after all implementation tasks land.

## Acceptance criteria
- `docs/settings.md` and relevant TUI docs accurately describe `tui.transcript.*`, visible/hidden thinking, tool YAML args, previews, and `Ctrl+O`.
- TUI snapshots or e2e fixtures are updated where appropriate.
- Product-level validation is run and reported: `castor run:agent-test` or `castor test:tui` (or `castor test:llm-real` if needed for real-model path).
- A real agent/real LLM validation test is created or updated to assert rich transcript block rendering, at minimum assistant message rendering and thinking block rendering.
- Validation exercises assistant Markdown, visible thinking, hidden thinking placeholder, tool call YAML args, normal tool result preview, diff preview, and `Ctrl+O`.
- Failures include captured snapshot/session artifacts: `events.jsonl`, `runtime-events.jsonl`, and `transcript.jsonl` where available.
- Card density is reviewed from snapshots; any final tweaks remain compact/subtle unless user feedback says otherwise.

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
- Created: 2026-05-22T19:09:21.898Z

## Task workflow update - 2026-05-22T19:11:12.284Z
- Summary: User requested the final validation task include creating/updating a real agent test with a real LLM path that verifies rich transcript block rendering, at minimum assistant messages and thinking blocks.
- Scope refinement: final task should create or update a real LLM/agent-level test (not only mocked/unit tests) to check how transcript blocks render in practice, at least for assistant messages and thinking blocks.
