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
Status: CODE-REVIEW
Branch: task/safe-05-safeguard-approval-ignored
Worktree: /home/ineersa/projects/agent-core-worktrees/safe-05-safeguard-approval-ignored
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/162
PR Status: open
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

## Task workflow update - 2026-06-17T22:02:06.136Z
- Summary: DESIGN CORRECTION (2026-06-17): The committed approach (commit-time subscriber) is NECESSARY but INSUFFICIENT. A scout confirmed the default `process` transport runs 5+ separate processes (HeadlessController.php:85-96: controller + run_control consumer + llm consumer + N tool workers + scheduler_default). SafeGuard approval state must cross TWO process boundaries, both of which the in-memory arrays cannot cross:
- registerPendingApproval()/markPending() run in the TOOL consumer (ToolCallRequested during ExecuteToolCall).
- resolveApproval()/onApprovalAnswered() (the new SafeGuardApprovalCommitSubscriber) run in the RUN_CONTROL consumer (ApplyCommand commit) -> DIFFERENT process -> resolveApproval() returns null -> silently skipped -> STILL BROKEN in default transport.
- consumeApproval() on retry runs in the tool consumer again (possibly a different worker) -> also can't see a run_control-marked approval.

USER-APPROVED FIX (Option A, cache-backed ledger): Back ExtensionHookRegistry pending approvals AND ApprovalSessionTracker approved set with a shared DBAL-backed Symfony cache pool. Crosses both process boundaries via the shared .hatfield SQLite. No entity/repo/migration (DBAL adapter self-manages cache_items table).

Cache design (locked with user):
- New pool `cache.approvals` -> cache.adapter.doctrine_dbal, provider = existing %app.cwd%/.hatfield/messenger.sqlite connection, default_lifetime 86400 (1 day — generous for user-walked-away; approved entries are consume-deleted so they don't accumulate).
- ALSO (user request, future infra): repoint `cache.app` (application cache) -> doctrine_dbal, same connection, default_lifetime 3600. KEEP `cache.default` + system/validator/serializer/framework caches on filesystem (do NOT repoint default). Scan confirmed NO src/ code currently injects CacheInterface/cache.app, so repointing app cache is safe now.
- Keys: `pending.<run_id>.<question_id>` = {hookId, opKey, details}; `approved.<run_id>.<op_key>` = decision.
- Hook identity crosses by ID, not object ref: pending entry stores hookId; resolve side looks up the live hook from a local id->hook map (SafeGuard is the only approval hook; hooks are boot-registered in every consumer process).
- consumeApproval(opKey) on retry reads `approved.<run>.<opKey>` from the SHARED cache (cross-process), returns true, deletes the key (one-time semantics for Allow once).
- "Always allow" unchanged: already durable via settings.yaml + classifier allowlist; does NOT touch the cache.
- Replace the in-memory arrays entirely (no backward-compat layer / dual-read); cache is the single source of truth.

MUST-VERIFY integration detail: the `cache_items` table must auto-create in the per-project SQLite (DoctrineBundle schema subscriber). The controller-replay E2E with a fresh var/tmp/test-{uuid} project will catch this if broken.

BRANCH DECISION: Extend the current branch/worktree (commit 7ea229a40 is the foundation; SafeGuardApprovalCommitSubscriber is what writes to cache at commit time). Do NOT split — splitting would ship a known-incomplete fix.
- Committed foundation on task/safe-05-safeguard-approval-ignored (7ea229a40): AfterTurnCommitEventSummary.payload + SafeGuardApprovalCommitSubscriber skeleton + in-process tests. All castor checks green but in-process only.
- Scout confirmed the committed fix is STILL BROKEN in the default process transport: registration (tool consumer) and resolution (run_control consumer) are in different processes; both ExtensionHookRegistry and ApprovalSessionTracker are in-memory singletons that cannot cross the boundary.
- User decision: fix with a shared DBAL-backed cache pool (`cache.approvals`, 1-day TTL). User additionally requested repointing `cache.app` (application cache, not cache.default) to DBAL for future use. Scan confirmed no src/ code consumes cache.app today.
- Launching continuation fork in the worktree to: add cache pools config, back registry+tracker with cache.approvals (hook-by-id), wire consume-from-cache on retry, and add a controller-replay E2E as the real acceptance gate.

## Task workflow update - 2026-06-17T22:03:53.472Z
- MIGRATION REQUIREMENT (user-confirmed, parallel-safety): cache_items table must ship as a real Doctrine migration, NOT rely on lazy auto-create. Lazy CREATE TABLE races under parallel consumer boot. Approach: add cache.approvals + cache.app pools (doctrine_dbal) -> run `bin/console doctrine:migrations:diff` (DoctrineDbalCacheAdapterSchemaListener auto-includes cache_items) -> commit generated migration. Canonical schema (vendor/symfony/cache/Adapter/PdoAdapter.php): table cache_items(item_id, item_data, item_lifetime, item_time) + index on item_time.
- PARALLEL-SAFETY GUARANTEE (verified): AgentCommand.php:156 runs StartupDatabaseMigrator BEFORE runController() (which spawns consumers at HeadlessController.php:85-96). So cache_items is created before any consumer boots — no race. Migrations live in migrations/Version*.php.

## Task workflow update - 2026-06-17T22:43:46.623Z
- Validation: orchestrator-ran castor test:controller-replay -> OK 2 tests 25 assertions; orchestrator-ran castor test (full) -> OK 2670 tests 7892 assertions; orchestrator-ran castor deptrac -> 0 violations; orchestrator-ran castor phpstan -> 0 errors; orchestrator-ran castor cs-check -> clean; orchestrator-ran castor test --filter=SafeGuard -> 142/142
- Summary: ORCHESTRATOR-VERIFIED COMPLETE. Branch task/safe-05-safeguard-approval-ignored, HEAD 7317a99f6 (on top of ce336092f the cross-process cache fix). 

The fix resolves the REAL root cause: SafeGuard approval state crosses two process boundaries in the default 'process' transport (tool consumer registers; run_control consumer commits; tool consumer retries), so in-memory singletons can't bridge it. Backed both ExtensionHookRegistry pending approvals and the approved decision with a shared DBAL cache pool (cache.approvals, 1-day TTL) over the existing .hatfield SQLite. cache.app (application cache) also repointed to DBAL per user request; cache.default/cache.system stay filesystem. cache_items table shipped as a real Doctrine migration (Version20260617141001) registered in KNOWN_MIGRATIONS to avoid the parallel CREATE TABLE race — runs via StartupDatabaseMigrator before consumers spawn. Hook identity crosses by id (class name) looked up from a local hooksById map. 2 stale in-process docblocks corrected in 7317a99f6.

ORCHESTRATOR INDEPENDENTLY RAN ALL VALIDATION (not trusting handoff):
- Controller-replay E2E (the acceptance gate, multi-process default transport): OK 2 tests, 25 assertions
- Full suite: OK 2670 tests, 7892 assertions (no regression from global cache.app->DBAL)
- deptrac: 0 violations; phpstan: 0 errors; cs-check: clean
- SafeGuard filter: 142/142
- Verified migration registration, cache wiring, cross-process logic, and that the comment-corrective commit (ce336092f..7317a99f6) is pure docblock (zero executable lines).

Two prior passes needed correction: (1) original commit 7ea229a40 ignored cross-process entirely; (2) second fork left work uncommitted with a failing E2E. Third pass + comment corrective fork landed it cleanly.

## Task workflow update - 2026-06-17T22:44:42.096Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Running deterministic castor check in worktree (timeout 480s)...
- castor check passed (29.0s).
- Pushed task/safe-05-safeguard-approval-ignored to origin.
- branch 'task/safe-05-safeguard-approval-ignored' set up to track 'origin/task/safe-05-safeguard-approval-ignored'.
- Created PR: https://github.com/ineersa/agent-core/pull/162

## Task workflow update - 2026-06-17T22:52:41.904Z
- Updated PR URL: https://github.com/ineersa/agent-core/pull/162
- Updated PR Status: open
- Summary: REVIEWER VERDICT: APPROVE WITH SUGGESTIONS (mergeable, no blockers). Independent reviewer subagent traced the cross-process flow end-to-end in real code, ran deptrac (0 violations) + phpstan (0 errors) itself, verified security (Deny doesn't write approved key; runId namespacing prevents cross-run bleed), migration idempotency, cache.app isolation (cache.system/default stay filesystem, no existing CacheInterface consumers in src/), and that the controller-replay E2E is a genuine multi-process test. Docs (hitl-and-approvals.md) updated.

Non-blocking suggestions raised by reviewer:
1. [LOW] consumeApproval() read-then-delete not atomic under concurrency — reviewer judged acceptable given run-serialized retries (single worker consumes per retry). Could add a comment.
2. [EDGE] Silent in-memory fallback when runId set but ledger null (DI misconfiguration) gives no diagnostic signal — suggests a warning log.
3. [EDGE] Stale markPending entry left in in-memory ApprovalSessionTracker when cache pre-check hits — harmless (in-memory, reset per process).
4. [SIMPLIFY] Dual-path verbose — NullCachedApprovalLedger for tests. Not needed (reviewer says dual-path is clear/correct).
5. [DEAD CODE] removePending()/removeApproved() unused — harmless cleanup helpers.
6. [COVERAGE] No test for Deny path (proves approved key NOT written) and no test for one-time semantics (approve → consume → re-prompt). Reviewer rated NTH.

Decision pending user: merge as-is, or iterate on a focused subset (#1 comment, #2 warning log, #6 the two tests) before task-done.
- task-to-pr step 2: reviewer subagent run on worktree. VERDICT=APPROVE WITH SUGGESTIONS, no blockers, mergeable. Reviewer independently ran deptrac (0) and phpstan (0), verified cross-process flow in real code, security (Deny/no cross-run bleed), migration idempotency, cache.app isolation, E2E test authenticity, docs sync. 6 non-blocking suggestions recorded.

## Task workflow update - 2026-06-17T23:31:47.979Z
- PIVOT (2026-06-17): User tested PR #162 and reported 'doesn't work' — root cause is NOT #130 (approval ignored; that was fixed and verified) but the SOFT-INTERRUPT FLOW: RequireApproval becomes a committed tool RESULT, the LLM sees it, and the MODEL manually retries the write (extra turn, 'blocked' messaging). Security gate is sound (setResult short-circuits the handler; no unauthorized write), but UX is broken.
- DESIGN CHANGE — Option A (blocking-poll pause/resume), user-approved: RequireApproval must NOT call setResult. Instead ExtensionToolHookEventSubscriber creates a ToolQuestion in the shared DB and BLOCKS on poll (reusing the RuntimeBashBackgroundPromptAdapter pattern). TUI shows the approval overlay via tool_question.requested. On answer, poll returns IN THE SAME tool worker, the real tool handler runs, the LLM sees the REAL result next turn — zero extra turns, no 'blocked' message.
- USER DECISIONS: (1) Continue on this branch (task/safe-05-safeguard-approval-ignored); rewrite/remove the cache-ledger code as needed. (2) NO timeout, NO TTL — tool worker blocks indefinitely, run stays halted until answered; crash/redelivery safety via idempotent re-attach to existing pending ToolQuestion by toolCallId.
- SUPERSEDED by Option A (DELETE/REWRITE): CachedApprovalLedger, cache.approvals pool, ExtensionHookRegistry cross-process ledger methods (+warnLedgerMisconfigured), SafeGuardApprovalCommitSubscriber, ExtensionToolHookEventSubscriber cache-check branch. KEEP: cache.app->DBAL (user requested for future cross-process needs), Version20260617141001 cache_items migration, ApplicationMigrationExecutor KNOWN_MIGRATIONS registration.
- NEW WORK: extend ToolQuestion for string answers (Allow once/Always allow/Deny) + migration; ExtensionToolHookEventSubscriber blocking-poll with idempotent re-attach; TickPollListener Approval-kind routing (3-button overlay -> answer_tool_question with string); AnswerToolQuestionHandler string support; verify messenger tool-transport redelivery semantics; verify ToolQuestionPoller stale-cancellation does NOT kill live no-TTL SafeGuard approvals; TUI E2E proof (mandatory gate).

## Task workflow update - 2026-06-18T01:37:46.464Z
- SMOKE-TEST FAILURE (session 2, HEAD 6152c97d2): user selected 'Allow once' for outside-CWD write to /tmp/test.md, then nothing happened (wedge).
- Ground truth from .hatfield/sessions/2/events.jsonl: only 5 events, last = tool_execution_start (seq 5). No tool_execution.completed. Run wedged after tool started.
- Ground truth from .hatfield/messenger.sqlite tool_question row id=1: request_id=sg_2_9gtIQ3..., kind=safeguard_approval, status=answered, answer(bool)=0, answer_text=NULL, created 01:22:53, answered 01:28:43. The answer WAS written ~6min later but via the BOOLEAN path (answer=0), NOT answerWithText.
- ROOT CAUSE: the live answer_tool_question command arrived WITHOUT kind=safeguard_approval, so AnswerToolQuestionHandler routed it to handleBooleanAnswer (storing answer=false, answer_text=NULL). The tool consumer's pollAnswerText() reads answer_text, sees NULL, returns NULL forever even though status=answered -> blocking poll never returns -> wedge.
- Contributing defect #1: AnswerToolQuestionHandler.php lines 77-80 has a comment PROMISING kind-inference from the stored ToolQuestion ('try to infer from the existing question in the store') but the code does NOT implement it - just falls through to handleBooleanAnswer. Misleading comment + missing robustness.
- Contributing defect #2: the live TUI/answer path omitted kind. TickPollListener::handleApprovalToolQuestion onAnswer DOES send kind=safeguard_approval, and ToolQuestionPoller DOES emit kind (lines 136-137). Why the live command lacked kind needs reproduction (possible QuestionController::Approval behavior or onAnswer closure not firing).
- COVERAGE GAP: controller-replay E2E (SafeGuardApprovalControllerReplayTest) PASSED but did not catch this because it injects the answer command directly WITH kind=safeguard_approval, bypassing (a) the real TUI render+select round-trip and (b) the kindless-answer failure mode. The mandatory TmuxHarness TUI E2E was never written - it would have caught this.

## Task workflow update - 2026-06-18T02:05:58.828Z
- Updated PR URL: https://github.com/ineersa/agent-core/pull/162
- Updated PR Status: open
- WEDGE FIX FORK (commit a0686e7ae): root cause of session-2 wedge proven from events.jsonl + tool_question row (answer arrived without kind=safeguard_approval -> boolean path -> answer_text=NULL -> pollAnswerText returned NULL forever). Fixed in 3 layers: (A) AnswerToolQuestionHandler implements kind-inference from stored ToolQuestion when payload omits kind; (B) TickPollListener Confirm handler now sends kind=confirm + diagnostic logging; (C) ExtensionToolHookEventSubscriber wedge hardening — answered-but-textless detected, logged, treated as Deny (no silent forever-poll). Added regression test (kindless answer) + MANDATORY TmuxHarness TUI E2E (SafeGuardApprovalTuiE2eTest: real TUI, 3-button overlay, Enter, file written outside CWD, no blocked/interrupt messaging).
- USER SMOKE TEST PASSED after wedge fix (session 3): write to /tmp/test.md -> SafeGuard approval overlay -> Allow once -> file written, no retry, no blocked messaging.
- INDEPENDENT VERIFICATION (orchestrator, not fork's word): test:tui 3/35 assertions 0 skipped (tmux really ran), new test by FQN 1/8 assertions not-skipped 7.9s, test:controller-replay 3/41, test 2665/7843, phpstan 0, deptrac 0, cs-check clean.
- PUSHED 3 commits (a40597d9b, 6152c97d2, a0686e7ae) to origin/task/safe-05-safeguard-approval-ignored. PR #162 head now a0686e7ae. PR description rewritten to document final blocking-poll design, smoke-test wedge fix, and superseded cache-ledger machinery.
- REVIEWER LAUNCHED.

## Task workflow update - 2026-06-18T02:30:16.614Z
- REVIEWER-ITERATION FORK (commit 1b4db1ed9): addressed 4 worthwhile reviewer suggestions. (1) Removed dead AfterTurnCommitEventSummary::payload field + stale comment in AfterTurnCommitHookContext + updated AfterTurnCommitSerializerRegressionTest. (2) Rewrote 4 stale sections of docs/hitl-and-approvals.md to describe the current blocking-poll architecture (old commit-subscriber flow, RequireApproval->human_input, answer routing, routing lifecycle). (3) Fixed Version20260617141001 migration docblock (removed deleted cache.approvals reference). (4) Added Layer C regression test testAnsweredWithoutTextInPollIsTreatedAsDeny — real integration test (real RegistryBackedToolbox+subscriber+EventDispatcher, only store stubbed) proving the session-2 wedge mode (answered-but-textless) is treated as Deny with safeguard.approval_answer_shape_mismatch warning, never hangs. 7 assertions, passes in 0.2s.
- INCIDENT (contained, no impact): a prior test run truncated .hatfield/settings.yaml (401->68 lines) in the worktree tree. Fork detected it via git diff --stat, restored from HEAD~1, amended commit. Orchestrator independently verified: commit 1b4db1ed9 touches exactly 6 intended files (no settings.yaml), .hatfield/settings.yaml is 400 lines, last touched by unrelated commit 04c309926, NOT in branch history. Tree clean.
- INDEPENDENT VERIFICATION (orchestrator): commit is exactly 6 files (docs/hitl-and-approvals.md, Version20260617141001.php, AfterTurnCommitEventSummary.php, AfterTurnCommitHookContext.php, AfterTurnCommitSerializerRegressionTest.php, ExtensionToolHookEventSubscriberTest.php). Layer C test in isolation 1/7 assertions 0.2s. All gates green: test:tui 3/35 0-skipped, test:controller-replay 3/41, test 2666/7850, phpstan 0, deptrac 0, cs-check clean.
- PUSHED commit 1b4db1ed9 to origin/task/safe-05-safeguard-approval-ignored. PR #162 head now 1b4db1ed9. Reviewer verdict was APPROVE WITH SUGGESTIONS (no blockers); all worthwhile suggestions now addressed.

## Task workflow update - 2026-06-18T02:49:07.564Z
- ARCHITECTURAL FINDING (user review of PR #162, 2026-06-17): the blocking-poll CORE is correct, but the integration violated OCP + SoC badly. SafeGuard domain is hardcoded across CodingAgent/TUI infra in 4 sites: (1) ExtensionToolHookEventSubscriber::handleRequireApproval hardcodes requestId prefix 'sg_', kind='safeguard_approval', the entire match('Allow once','Always allow','Deny') outcome map, 'safeguard_denied'/'denied by SafeGuard' strings, safeguard.* log names; (2) AnswerToolQuestionHandler routes if('safeguard_approval'===$kind) + kind-inference hack; (3) TickPollListener::handleApprovalToolQuestion hardcodes fallback schema ['Allow once','Always allow','Deny'] + sends kind=safeguard_approval; (4) QuestionKind::Approval + QuestionController::approvalItems() is a SafeGuard-specific overlay. Root cause of the mess: SafeGuard ALREADY supplies everything via requireApproval(prompt, questionId, schema{enum:[Allow once,Always allow,Deny]}, details) but infra DUPLICATES it instead of USING it. A second approval-granting extension cannot exist without editing all 4 infra sites.
- APPROVED REDESIGN: (1) Add resolveApprovalAnswer(ApprovalAnswerContextDTO): ToolCallDecisionDTO to ApprovalAnswerHookInterface (user chose this over a split interface) — extension owns outcome; onApprovalAnswered stays for side-effects. (2) Subscriber becomes generic: requestId namespaced by hook identity (no 'sg_' literal), generic question kind, calls $hook->resolveApprovalAnswer() and applies returned Allow/Block/ReplaceResult, generic log names. (3) AnswerToolQuestionHandler routes by STORED SCHEMA TYPE (boolean->answer(), string/enum->answerWithText()) not by kind — this DISSOLVES the Layer-A wedge-hack entirely (no extension kind to go missing). (4) TUI: delete handleApprovalToolQuestion + QuestionKind::Approval + approvalItems(); one schema-driven renderer (enum->Choice, boolean->Confirm, else Text); answer sent with no extension-specific kind. Moves into SafeGuard: answer vocabulary+enum, resolveApprovalAnswer impl, 'safeguard_denied' strings, Always-allow persistence (already there). STAYS (the real #130 fix): blocking-poll core, shared SQLite store, ToolQuestionPoller, idempotent re-attach, cache.app->DBAL, migration. OCP proof: a second dummy approval extension must work through generic infra with zero edits to subscriber/handler/TUI.

## Task workflow update - 2026-06-18T03:24:56.887Z
- Updated PR Status: open
- OCP/SOC REFACTOR FORK (commit 05468bb36): addressed all 4 of user's PR review comments about architecture violation. Added resolveApprovalAnswer(ApprovalAnswerContextDTO):ToolCallDecisionDTO to ApprovalAnswerHookInterface (extension owns outcome). SafeGuard now owns its full answer->outcome map + 'safeguard_denied'/'safeguard_unknown_answer' strings + the ['Allow once','Always allow','Deny'] enum constant. Subscriber became generic: requestId=hash(crc32b,hook::class)_runId_toolCallId (no 'sg_' literal), kind='approval', calls $hook->resolveApprovalAnswer() + applies Allow/Block/ReplaceResult via ToolCallDecisionKindEnum, log names tool.approval_*. Removed the match('Allow once'...) outcome map entirely. AnswerToolQuestionHandler routes by STORED SCHEMA TYPE (boolean->answer(), enum/string->answerWithText()) — this DISSOLVED the Layer-A kind-inference wedge-hack entirely (no extension kind to go missing). TUI: deleted handleApprovalToolQuestion + QuestionKind::Approval + approvalItems(); one schema-driven renderer (enum->Choice, boolean->Confirm, else->Text). 13 files, +830/-756.
- OCP PROOF TEST (testOcpProofSecondApprovalExtensionWorksGenerically): dummy extension with its OWN vocabulary ('Proceed'/'Abort', 'dummy_denied') drives the generic subscriber end-to-end (allow + deny paths) with ZERO infra edits. 11 assertions. Independently re-run: PASS. This is the concrete proof the Open-Closed Principle is restored — a second approval extension works through generic infra without editing subscriber/handler/TUI.
- RESIDUAL CLEANUP FORK (commit 1f0efe93f, includes orchestrator doc fix): removed 2 leftover SafeGuard references in TickPollListener::handleHumanInputRequested (the legacy human_input/answer_human path, NOT the tool_question blocking-poll path). 'Deny' cancel/fallback answers -> 'cancel' generic sentinel (empty string not viable: AnswerHumanHandler:74-81 rejects empty with protocol error; SafeGuard's resolveApprovalAnswer fail-closed default treats 'cancel' as block). Doc comment '(e.g. SafeGuard)' -> generic. Also amended: QuestionCoordinator.php:14 stale doc updated from 'fail-safe Deny' to 'generic cancel sentinel' (orchestrator direct edit, doc-only).
- INDEPENDENT VERIFICATION (orchestrator): zero-leak check PASS — grep for 'Deny'|safeguard_approval|'Allow once'|'Always allow' across src/Tui/ + subscriber + answer handler returns NOTHING; all SafeGuard vocabulary now confined to src/CodingAgent/Extension/Builtin/SafeGuard/. QuestionKind::Approval fully removed (remaining Approval hits are different enums: TranscriptBlockKindEnum, RuntimeEventTypeEnum). resolveApprovalAnswer confirmed in SafeGuard (SafeGuardToolCallHook:216), NOT in subscriber. ApprovalAnswerHookInterface declares both onApprovalAnswered + resolveApprovalAnswer.
- ALL GATES GREEN (independently re-run): OCP proof test 1/11, full suite 2667/7850, test:tui 3/35 0-skipped, test:controller-replay 3/41, phpstan 0, deptrac 0 (ExtensionApi layering intact), cs-check clean. QuestionCoordinator/TickPoll/AnswerHuman sanity 41/101.
- PUSHED 4 commits (1b4db1ed9, a0686e7ae, 05468bb36, 1f0efe93f) to origin. PR #162 head now 1f0efe93f. All user-flagged OCP/SoC violations fixed; blocking-poll core untouched; TUI and infra know zero extensions.
- REVIEWER LAUNCHED for full architectural review of OCP/SoC refactor.
