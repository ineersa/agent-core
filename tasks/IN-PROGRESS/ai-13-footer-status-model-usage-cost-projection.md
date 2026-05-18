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
Status: IN-PROGRESS
Branch: task/ai-13-footer-status-model-usage-cost-projection
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-13-footer-status-model-usage-cost-projection
Fork run: gucsjk5ug5x5
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

## Task workflow update - 2026-05-18T00:28:54.060Z
- Validation: php -l changed PHP files: clean; php bin/console --no-interaction: boots; vendor/bin/phpunit tests/Tui/Footer/: passed (7 tests, 16 assertions); castor test: passed (353 tests, 8122 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations, 369 allowed); castor phpstan: passed (0 errors; baseline 225); castor cs-check: clean
- Summary: Follow-up PR #21 footer smoke fixes completed by fork mk0q65oukv56 in commit 6e2b6632, pushed to branch task/ai-13-footer-status-model-usage-cost-projection. Fixed user-reported footer issues: added theme-based per-segment FooterSegment colors, changed FooterBarWidget separators/order to match expected footer, added stubs for missing model/token/cost/context/elapsed data, removed default ChatScreen footer clutter except session segment, and kept TUI boundary clean. Cost and context remain stubs (`$--`, `--%`) until rate-card/context-window data is available in the footer state path.

## Task workflow update - 2026-05-18T00:35:55.976Z
- Moved CODE-REVIEW → IN-PROGRESS.

## Task workflow update - 2026-05-18T00:36:08.860Z
- Recorded fork run: pchye2hjzfmj
- Summary: Launched second AI-13 footer fix fork after comparing snapshots. Current visible footer from 203020 is only `◆ no-model  |  0/0 $-- --%`; expected 202021 is Pi-style full line `◆ gpt-5.5 | 0/0 $0.00 0% 0/272.0k | ⏱ 0s | ⌂ agent-core-worktrees/... | ⎇ task/...` plus IDE status line. Fork given exact implementation plan: fix ANSI truncation via AnsiUtils, split diamond/model coloring, remove standalone reasoning label, use Pi token/cost/context layout, last-two path components, status entries as second line, theme colors only, and no model/thinking changes.

## Task workflow update - 2026-05-18T00:39:06.201Z
- Recorded fork run: gucsjk5ug5x5
- Summary: Launched second follow-up fork after user reported snapshot 203020 is worse than expected 202021. Fork received an exact implementation plan: fix ANSI-unsafe truncation in FooterBarWidget, seed startup model/context from AppConfig, match Pi custom-footer composition (diamond+model, token block, optional t/s, elapsed, cwd, branch), remove separate reasoning text, use Pi-compatible colors/stubs/number formatting, short cwd last two path segments, and actually implement/commit/push changes.

## Task workflow update - 2026-05-18T00:43:03.463Z
- Validation: php -l changed PHP files: clean; php bin/console --no-interaction: boots; vendor/bin/phpunit tests/Tui/Footer/: passed (7 tests, 16 assertions); castor test: passed (353 tests, 8122 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations, 368 allowed); castor phpstan: passed (0 errors; baseline regenerated to 224); castor cs-check: clean
- Summary: Second footer smoke fix completed by fork gucsjk5ug5x5 in commit 50b6504d, pushed to branch task/ai-13-footer-status-model-usage-cost-projection. Fixed root causes from snapshot comparison: ANSI-aware footer truncation, AppConfig default model/context fallback (no startup `no-model`), Pi-style composition without separate reasoning text, `$0.00` and `0%` stubs, Pi `k` number formatting, short cwd last-two-path-parts, and deptrac AppConfig allowance for TuiListener. Expected stripped footer now: `◆ deepseek-v4-pro | 0/0 $0.00 0% 0/200.0k | ⏱ 0s | ⌂ agent-core-worktrees/... | ⎇ task/...`.
