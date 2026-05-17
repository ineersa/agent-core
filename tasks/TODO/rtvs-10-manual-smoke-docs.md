# RTVS-10 Manual runtime transcript smoke test and docs

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Document and run the manual smoke path for the runtime transcript vertical slice.
- Use castor run:agent against a configured local/remote model where available.
- Verify prompt submission, streaming or incremental assistant text, runtime-events.jsonl persistence, transcript.jsonl projection data, and resume display.
- Record known limitations and follow-up tasks if rich rendering/tool widgets are missing.

Exclusions:
- Do not add live-model tests to the default castor check path.
- Do not require AI-12 real llama.cpp smoke unless the environment is ready.
- Do not implement new production behavior beyond fixes found during smoke.

Dependencies: RTVS-09; AI-12 optional.
Parallelizable with: none after dependencies.

## Acceptance criteria
- Manual smoke instructions are documented in the plan or relevant docs.
- A smoke run verifies user prompt, assistant response, persisted runtime events, transcript projection, and resume display.
- Known limitations/follow-ups are recorded without broadening the task scope.
- No tmux e2e tests are added to castor check.
- castor deptrac and focused tests still pass after any fixes.

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
- Created: 2026-05-17T22:17:27.342Z
