# Issue 137 restart: generalized model notification system

## Goal
Restart for issue #137 after PR #164 rejection and rollback PR #165. Do not reuse the rejected output-cap-specific plumbing branch except as a cautionary reference. The architectural goal is a generalized model-facing notification/nudge system usable by OutputCap, SafeGuard, extensions, and future internal guidance.

Problem statement:
- Generated model-facing messages (output cap notices, SafeGuard denials/approvals, extension nudges, internal guidance) must be represented as first-class structured data.
- The exact text sent to the model must be visible in the TUI/events log exactly as sent: no paraphrase, no summary, no hidden assistant/system messages.
- Production code must not infer notice type by parsing arbitrary text (no preg_match/str_contains/str_starts_with on notice text). Notice identity/severity/source must be structured metadata.
- OutputCap should become a producer/user of the generic notification system, not a special case threaded through RuntimeEventTranslator, ToolProjectionSubscriber, and TUI renderer.

Required planning before implementation:
1. Freeze the generic abstraction/API: name, DTO/envelope shape, source/severity/kind fields, exact text field, target audience/model visibility, relation to tool_call_id/run/turn/step.
2. Identify one canonical persistence/projection path from producer → model input → events.jsonl/runtime events → TranscriptProjector/TUI.
3. Decide where notifications are injected into model context (tool result transform hook, tool executor result envelope, or a dedicated model-context notification collector) without output-cap-specific branching.
4. Decide how producers attach notifications: OutputCap first; SafeGuard/extensions should be possible without implementation-specific hacks.
5. Define a minimal validation strategy: one generic contract/regression test plus one real TmuxHarness E2E proof for output cap. Avoid enum/DTO/mapper noise tests.

Implementation scope after plan approval:
- Add the generalized model notification primitive and flow.
- Convert OutputCap to emit/use it as the first producer.
- TUI renders generic notifications as proper notice/System blocks using structured severity/kind/source metadata.
- Preserve normal ToolResult readability; capped output should not leak raw/full output where the model saw only a notice.
- Do not implement SafeGuard-specific styling unless explicitly added later; only ensure the generic path can support it.

## Acceptance criteria
- A short architecture plan is written and approved before implementation starts; no fork should implement before the abstraction is agreed.
- Generic model-notification DTO/envelope exists with exact model-facing text and structured kind/source/severity metadata; no output-cap-specific protocol plumbing through unrelated layers.
- Production code does not parse notice text to detect output caps or notice types.
- OutputCap emits a generic model notification and uses the common flow; TUI shows exactly that notice once via a generic notice/System block.
- Normal uncapped ToolResult display remains human-readable; capped raw/full output is not shown when the model receives only a notification.
- One TmuxHarness replay-backed E2E proof demonstrates the user-visible output-cap notification behavior.
- Tests are minimal and value-based: generic contract/regression proof plus output-cap integration/E2E proof; no enum/DTO/getter/private-helper/coverage-only tests.
- SafeGuard-specific UX remains deferred unless explicitly requested, but the generic design can support SafeGuard/extension producers.

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
- Created: 2026-06-18T17:14:09.183Z
