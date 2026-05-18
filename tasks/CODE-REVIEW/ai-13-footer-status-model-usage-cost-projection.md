# AI-13 Footer/status projection for model, reasoning, usage, and cost

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-13--footerstatus-projection-for-model-reasoning-usage-and-cost

Goal: expose runtime data needed by the footer without implementing the full picker yet.

Depends on: AI-05, AI-06, AI-10.

Parallelism: can run alongside AI-11 and AI-12 once provider + routing path exists; unblocks AI-14.

Scope:
- Add runtime/projection events or state for current model, reasoning level, token usage, cost estimate, context window usage, tokens/sec, session elapsed time, cwd and git branch if not already exposed.
- Use `FooterSegmentProvider`/`FooterDataProvider` extension points, not direct widget mutation.
- Format toward: `◆ model | thinking | tokens cost context% | ⚡ t/s | ⏱ time | ⌂ cwd | ⎇ branch`.

## Acceptance criteria
- Footer can show selected model and reasoning after run start.
- Usage/cost/context update after assistant result when metadata is available.
- TUI boundary stays clean: `src/Tui/` does not import AgentCore internals.
- Suggested validation: `castor test --filter Footer`; `castor deptrac`.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/ai-13-footer-status-model-usage-cost-projection
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-13-footer-status-model-usage-cost-projection
Fork run: mk0q65oukv56
PR URL: https://github.com/ineersa/agent-core/pull/21
PR Status: open
Started: 2026-05-17T23:55:24.914Z
Completed:

## Work log
- Created: 2026-05-16T22:02:34.213Z

## Task workflow update - 2026-05-17T23:55:24.914Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-13-footer-status-model-usage-cost-projection.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-13-footer-status-model-usage-cost-projection.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-13-footer-status-model-usage-cost-projection.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-13-footer-status-model-usage-cost-projection.

## Task workflow update - 2026-05-17T23:55:44.837Z
- Recorded fork run: 4ys30ypxby11
- Summary: Started AI-13 implementation in worktree /home/ineersa/projects/agent-core-worktrees/ai-13-footer-status-model-usage-cost-projection. Fork instructed to work only inside the worktree, keep TUI boundary clean, implement footer/status projection data for model/reasoning/usage/cost/context/tokens-sec/session/cwd/branch, and avoid AI-14 picker/favorites or RTVS transcript work.

## Task workflow update - 2026-05-18T00:06:15.455Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-13-footer-status-model-usage-cost-projection to origin.
- branch 'task/ai-13-footer-status-model-usage-cost-projection' set up to track 'origin/task/ai-13-footer-status-model-usage-cost-projection'.
- Created PR: https://github.com/ineersa/agent-core/pull/21
- Validation: php bin/console --no-interaction: OK; castor test: passed (353 tests, 8122 assertions, 1 pre-existing PHPUnit notice); castor deptrac: passed (0 violations, 347 allowed); castor phpstan: passed (0 errors; baseline regenerated to 226 entries); castor cs-check: clean
- Summary: AI-13 implemented in commit 40cb8abe on branch task/ai-13-footer-status-model-usage-cost-projection. Added footer/status projection using existing TUI extension points: new FooterStateListener registers a FooterSegmentProvider, TuiSessionState stores model/reasoning/token/session/cwd/branch footer fields, RuntimeEventPoller accumulates token usage from llm_step_completed events, ChatScreen exposes addFooterProvider(), and deptrac rules allow TuiListener -> TuiFooter. Footer now displays model, reasoning, token usage, tokens/sec, elapsed session time, cwd, and git branch. Cost and context-window percentage intentionally left for follow-up because reliable rate-card/context lookup is not currently in the footer data path.

## Task workflow update - 2026-05-18T00:24:35.801Z
- Recorded fork run: mk0q65oukv56
- Summary: Launched follow-up fork to address user smoke-test feedback on PR #21 footer output: compare current and expected ANSI snapshots, use my-pi custom-footer.ts as reference, restore theme colors, fix ordering, add stubs for unavailable data, and remove default ChatScreen footer clutter except session segment. Scope remains AI-13 footer/status projection only; no AI-14 controls or RTVS work.
