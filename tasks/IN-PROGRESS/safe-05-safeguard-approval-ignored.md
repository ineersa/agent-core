# Fix SafeGuard approval ignored — writes outside CWD always blocked (#130)

## Goal
## Source

GitHub issue #130 — "SafeGuard doesn't work properly": when the model tries to write outside CWD, no matter what the user responds (Allow once / Always allow), the write is **always blocked**. Also verify "Always allow" is added to settings properly so future writes are auto-allowed.

## Confirmed root cause (verified by reading the code)

**Cross-process approval-state isolation in the DEFAULT `process` (controller) TUI transport.** The approval *decision* is observed in a different process than the one that owns the approval *state*.

Default transport is `process` (`src/CodingAgent/CLI/AgentCommand.php:70-71` — `string $transport = 'process'`; in-process is "sync, broken after ASYNC-05"). In this mode:

1. **Tool worker process** runs tool execution → `SafeGuardToolCallHook::onToolCall()` returns `RequireApproval`; `ExtensionToolHookEventSubscriber` registers the pending approval in the **worker's** `ExtensionHookRegistry`, and `ApprovalSessionTracker::markPending()` in the **worker's** tracker. → `WaitingHuman`.
2. User answers → `answer_human` → `ApplyCommandHandler::applyHumanResponseCommand()` commits `AgentCommandApplied(kind=human_response, question_id, answer)` and queues a `postCommit` `AdvanceRun` (the retry). (`src/AgentCore/Application/Pipeline/ApplyCommandHandler.php`, `applyHumanResponseCommand` + `followUpAdvanceCallback`.)
3. `RuntimeEventTranslator::translate()` is what dispatches `agent_command_applied` to `ExtensionApprovalAnswerSubscriber` — but `translate()` runs in the **controller process** drain loop (`RuntimeEventEmitter`), whose `ExtensionHookRegistry` is **empty**. So `ExtensionApprovalAnswerSubscriber::onAgentCommandApplied()` → `resolveApproval(questionId)` → **null** → `SafeGuardToolCallHook::onApprovalAnswered()` **never runs**.
4. `postCommit` `AdvanceRun` fires (in the worker) → LLM retries the tool → `SafeGuardToolCallHook::onToolCall()` → `consumeApproval()` → **false** → re-blocked. Forever. ⇒ "always blocked".

Because `onApprovalAnswered()` never fires, **"Always allow" is also never persisted** (`SafeGuardPolicyWriter::addAllowPattern()` is only called from `onApprovalAnswered()`).

Note: the same race exists even in-process — `translate()`/marking happens during TUI **polling**, while the `postCommit` retry runs **synchronously** inside `send()` before polling marks the approval — so in-process also re-blocks at least once. The cross-process case is strictly worse (never recovers).

## Exact code path (file:line)

- `src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardToolCallHook.php`
  - `onToolCall()`: `consumeApproval()` (line ~65) → classify → `RequireApproval` with `operation_key` + `markPending()` (line ~102).
  - `onApprovalAnswered()`: routes Allow once / Always allow / Deny → `approveByQuestionId()` and (Always allow) `policyWriter->addAllowPattern()`. **This is the method that never gets called.**
- `src/CodingAgent/Extension/ExtensionApprovalAnswerSubscriber.php` — listens on `agent_command_applied`; calls `hookRegistry->resolveApproval()` then `hook->onApprovalAnswered()`. Triggered ONLY via `RuntimeEventTranslator::translate()` (polling/controller), not at commit time in the worker.
- `src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php` — `translate()` does `$this->eventDispatcher->dispatch($runEvent, $type)` (line ~86). Only called from the TUI poll / controller drain, NOT from commit.
- `src/CodingAgent/Runtime/Protocol/RuntimeEventMapper.php` — delegates to `translate()`.
- `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php` — `applyHumanResponseCommand()` emits the event + `postCommit` `AdvanceRun` (retry) via `followUpAdvanceCallback()`.
- `src/AgentCore/Application/Pipeline/RunMessageProcessor.php` — `processWithRetry()`: calls `runCommit->commit()` THEN runs `$result->postCommit` callbacks (the retry). **The retry runs before any polling-based marking.**
- `src/AgentCore/Application/Pipeline/RunCommit.php` — `commit()` persists events, then calls `$this->hookDispatcher?->dispatchAfterTurnCommit(...)` BEFORE returning (i.e. before `postCommit`/retry). **This is the synchronous commit-time seam.**
- `src/AgentCore/Domain/Extension/AfterTurnCommitHookContext.php` + `AfterTurnCommitEventSummary.php` — **`AfterTurnCommitEventSummary` only has `seq` + `type`, NO `payload`** (the blocker for using this seam as-is).
- `src/AgentCore/Contract/Extension/HookSubscriberInterface.php` + `src/AgentCore/Application/Handler/HookDispatcher.php` + `HookSubscriberRegistry.php` — the AgentCore extension-hook contract; tag `agent_core.hook_subscriber` is wired (`config/services.yaml:46-47, 482-484`). NOTE: verify `HookDispatcher` is actually injected non-null into `RunCommit` (it's `?HookDispatcher = null`).
- `src/CodingAgent/Extension/ExtensionHookRegistry.php` — per-process pending approvals.
- `src/CodingAgent/Extension/Builtin/SafeGuard/ApprovalSessionTracker.php` — per-process in-memory approvals (operation-key keyed, one-time consume).
- `src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardPolicyWriter.php` — persists to `.hatfield/settings.yaml` under `extensions.settings.safe_guard.{allow_write_outside_cwd | allow_command_patterns}`. Works in isolation; just never invoked.

## Recommended fix direction (fork to finalize after writing a failing repro)

**Make approval-marking happen synchronously during commit, in the SAME (worker) process that performs tool execution, BEFORE the `postCommit` `AdvanceRun` retry.** This is correct for both in-process and controller/process modes (commit + tool execution both occur in the worker).

Preferred approach:
1. Extend `AfterTurnCommitEventSummary` to carry the event `payload` (array), and populate it in `AfterTurnCommitHookContext::fromRunState()`. Ensure it survives the normalize→denormalize round-trip in `HookDispatcher::dispatchAfterTurnCommit()` (Symfony serializer attributes as needed).
2. Add a CodingAgent `HookSubscriberInterface` (tag `agent_core.hook_subscriber`) that, in `handleAfterTurnCommit()`, scans committed events for `agent_command_applied` with `kind=human_response` and routes each answer to the originating hook via `ExtensionHookRegistry::resolveApproval()` → `onApprovalAnswered()` (extract the shared routing logic; the current `ExtensionApprovalAnswerSubscriber` is the source).
3. Remove the now-redundant polling-based `ExtensionApprovalAnswerSubscriber` (per the "no backward-compatibility code" rule) IF the commit-time subscriber fully supersedes it; otherwise document why both are needed. Verify Deptrac stays green (AgentCore must not depend on CodingAgent).

Alternative (if the summary-payload change is too invasive): dispatch the committed `agent_command_applied` event to the Symfony `event_dispatcher` from `RunMessageProcessor::processWithRetry()` immediately after a successful commit and BEFORE running `postCommit` callbacks. AgentCore already depends on Symfony `EventDispatcherInterface` (see `HookDispatcher`), so this stays layering-clean. Be targeted to avoid double-dispatch side effects.

`Always allow` then works automatically because `onApprovalAnswered()` (now actually invoked) calls `SafeGuardPolicyWriter::addAllowPattern()`, and subsequent writes match the persisted policy in `SafeGuardClassifier` (already covered by `testWriteOutsideCwdAllowlistedIsAllowed`).

## Required deliverables

1. **Failing reproduction FIRST** — a deterministic end-to-end test in the DEFAULT process/controller transport (replay fixtures for the LLM; group `controller-replay` or a process-mode pipeline integration test) that exercises: write outside CWD → WaitingHuman → `answer_human("Allow once")` → assert the write **executes** (not re-blocked). Confirm the bug, then the fix turns it green. Hook-only unit tests are NOT sufficient (they pass today despite the bug).
2. The fix (above).
3. A test proving **Always allow** writes the path to `.hatfield/settings.yaml` (`extensions.settings.safe_guard.allow_write_outside_cwd`) and a subsequent write to the same path is auto-approved with no prompt.
4. Keep existing SafeGuard unit tests green; update any that encoded the old (broken) timing assumptions.
5. Read `tests/AGENTS.md` and load the `testing` skill before writing tests; follow isolation/`var/tmp/test-{uuid}` conventions.

## Validation (run focused Castor before declaring done; do NOT move to CODE-REVIEW yourself)

- `castor test`
- `castor deptrac` (layering: AgentCore must not depend on CodingAgent/TUI)
- `castor phpstan`
- `castor cs-check`
- If touching the controller live path: `castor test:controller` (replay) as opt-in.
- This is NOT primarily a TUI-rendering change, so a TUI TmuxHarness E2E is not strictly required, but add one if the approval-prompt path is cheaply exercisable via replay fixtures.

## Out of scope / notes

- The separate TypeScript `safe-guard` extension in `/home/ineersa/claw/my-pi/packages/extensions/` is a DIFFERENT product (pi, not Hatfield/agent-core) and is NOT part of this issue. Do not modify it.
- Do not add backward-compat shims. Replace the broken path.
- Preserve explanatory comments (signal handling, ordering, transaction/lifecycle rationale); update them when logic changes.

## Acceptance criteria
- GitHub issue #130 resolved: approving a write-outside-CWD (Allow once) actually executes the write in the DEFAULT process/controller transport
- No re-prompt / re-block after a valid approval
- Always allow persists the path/command to .hatfield/settings.yaml under extensions.settings.safe_guard.{allow_write_outside_cwd|allow_command_patterns} and subsequent writes to that path are auto-approved without a prompt
- A deterministic end-to-end test (process/controller transport, replay fixtures for the LLM) proves allow-once executes and allow-always persists+auto-approves; pure hook unit tests are not accepted as the sole proof
- castor test, castor deptrac, castor phpstan, castor cs-check all pass; AgentCore stays free of CodingAgent/TUI dependencies

## Workflow metadata
Status: IN-PROGRESS
Branch: task/safe-05-safeguard-approval-ignored
Worktree: /home/ineersa/projects/agent-core-worktrees/safe-05-safeguard-approval-ignored
Fork run:
PR URL:
PR Status:
Started: 2026-06-17T20:44:06.133Z
Completed:

## Work log
- Created: 2026-06-17T20:43:49.337Z

## Task workflow update - 2026-06-17T20:44:06.133Z
- Moved TODO → IN-PROGRESS.
- Created branch task/safe-05-safeguard-approval-ignored.
- Created worktree /home/ineersa/projects/agent-core-worktrees/safe-05-safeguard-approval-ignored.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/safe-05-safeguard-approval-ignored.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/safe-05-safeguard-approval-ignored.

## Task workflow update - 2026-06-17T21:13:59.372Z
- Validation: castor test -> 2670/2670 pass; castor deptrac -> 0 violations; castor phpstan -> 0 errors; castor cs-check -> clean; castor test --filter SafeGuard -> 142/142 pass
- Summary: Implementation complete on branch task/safe-05-safeguard-approval-ignored, commit 7ea229a40 (worktree ../agent-core-worktrees/safe-05-safeguard-approval-ignored). 10 files changed (+908/-87).

APPROACH (chosen): Commit-time routing. Extended AfterTurnCommitEventSummary with optional `payload` (+ regression test for serializer round-trip), added new CodingAgent HookSubscriberInterface impl `SafeGuardApprovalCommitSubscriber` that routes human_response answers via ExtensionHookRegistry::resolveApproval() → onApprovalAnswered() synchronously inside RunCommit::commit() BEFORE the postCommit AdvanceRun retry, and DELETED the broken polling-based `ExtensionApprovalAnswerSubscriber`. Auto-tagged via services.yaml _instanceof (agent_core.hook_subscriber). Correct for both in-process AND process/controller transports because commit + tool execution both occur in the worker process. "Always allow" persistence now works automatically (onApprovalAnswered actually runs → SafeGuardPolicyWriter::addAllowPattern()).

VALIDATION (all green, run by fork in worktree): castor test 2670/2670 pass (7892 assertions); castor deptrac 0 violations; castor phpstan 0 errors; castor cs-check clean. 5 new tests added (SafeGuardApprovalCommitSubscriberTest x4, SafeGuardApprovalEndToEndTest x1, AfterTurnCommitSerializerRegressionTest). 142 SafeGuard tests pass.

VERIFIED BY ORCHESTRATOR: commit exists, diff stat sane, new subscriber logic correct, _instanceof auto-tagging confirmed, no dangling code refs to deleted class.
- Scouts (project PHP + TS extension package) + orchestrator code reads confirmed root cause: SafeGuard approval answers routed via ExtensionApprovalAnswerSubscriber which only fires from RuntimeEventTranslator::translate() during controller/poller polling — different process than the tool-worker that owns ExtensionHookRegistry/ApprovalSessionTracker. resolveApproval() returned null in controller → onApprovalAnswered() never ran → approvals always ignored/re-blocked, and Always-allow never persisted. Default TUI transport is 'process' (AgentCommand.php:70-71), so this is the active path.
- Fork implemented commit-time routing fix + 5 tests + docs. All castor checks green in worktree.
- RESIDUAL GAP 1 (main deviation): no full controller-replay/subprocess E2E test. Fork substituted a pipeline integration test (SafeGuardApprovalEndToEndTest) exercising real RunCommit::commit()->HookDispatcher->subscriber with real stores. This proves the fix mechanism and the commit→approval-marking path but does NOT spawn a real controller subprocess with multi-turn replay fixtures. Original acceptance asked for a process/controller-transport E2E.
- RESIDUAL GAP 2 (deeper, out of scope): multi-consumer-worker isolation. If RequireApproval is registered in worker A and the answer_human is committed by worker B, worker B's registry is empty. The run lock serializes per-run but does not pin a run to a single worker process. A shared persistent approval store (DB/fs) would be needed for that topology. The current fix resolves the documented default-transport 'always blocked' bug and the single-worker/in-process cases.
- Cosmetic: stale comment at config/services.yaml:367 still names the deleted ExtensionApprovalAnswerSubscriber.
