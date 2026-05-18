# AI-14 TUI model controls and favorites

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-14--tui-model-controls-and-favorites

Goal: add user controls for switching models/reasoning from the TUI.

Depends on: AI-07, AI-08, AI-13.

Parallelism: final UI task after selection, runtime input, and footer projection exist.

Scope:
- `/model` overlay/list: favorites first, all configured provider models after favorites, scrollable, `Ctrl+F` toggles favorite, `Enter` selects.
- `Ctrl+P` cycles favorite models.
- `Shift+Tab` cycles reasoning levels: `off -> minimal -> low -> medium -> high -> xhigh -> off`.
- Persist model/reasoning changes through selection services.
- Emit/update runtime events so footer changes immediately.

## Acceptance criteria
- User can select a model before the next turn.
- Favorite cycling only cycles configured/favorited models.
- Reasoning cycle updates session/home defaults and footer state.
- Existing TUI snapshots are updated only if rendering intentionally changes.
- Suggested validation: `castor test --filter Tui`; `castor test:tui`.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/ai-14-tui-model-controls-favorites
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-14-tui-model-controls-favorites
Fork run: 4vwkef8byjpz
PR URL:
PR Status:
Started: 2026-05-18T21:35:36.236Z
Completed:

## Work log
- Created: 2026-05-16T22:02:34.212Z

## Task workflow update - 2026-05-18T21:35:36.236Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-14-tui-model-controls-favorites.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-14-tui-model-controls-favorites.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-14-tui-model-controls-favorites.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-14-tui-model-controls-favorites.

## Task workflow update - 2026-05-18T21:36:08.221Z
- Recorded fork run: 44s6f5qp08oq
- Summary: Launched background fork to implement AI-14 in worktree /home/ineersa/projects/agent-core-worktrees/ai-14-tui-model-controls-favorites.

## Task workflow update - 2026-05-18T22:06:17.425Z
- Recorded fork run: 44s6f5qp08oq
- Validation: vendor/bin/phpunit tests/Tui/Listener/ModelCommandHandlerTest.php — 15 tests, 41 assertions, OK; vendor/bin/phpunit tests/CodingAgent/Config/ModelSelectionServiceTest.php — OK with new tests; ~/.local/bin/castor test — 500 tests, 8425 assertions, OK (1 pre-existing notice); ~/.local/bin/castor deptrac — 0 violations; ~/.local/bin/castor phpstan — 0 errors; ~/.local/bin/castor cs-check — clean
- Summary: AI-14 fork completed at commit 03735c79. Implemented /model command via slash registry, Ctrl+P favorite cycling, Shift+Tab reasoning cycling, favorite_models parsing/persistence, footer state updates, and tests. Fork notes deviations/open risks: no interactive overlay/Ctrl+F/Enter picker; uses /model fav/select commands instead; favorites persisted to disk but in-memory AiConfig may be stale until restart; no runtime events emitted beyond direct TuiSessionState footer update.

## Task workflow update - 2026-05-18T22:07:38.797Z
- Recorded fork run: 4vwkef8byjpz
- Summary: Launched AI-14 follow-up fork to fix review blockers before code review: stale in-process favorites after /model fav toggles, idempotent /model registration across repeated TUI runs, and required docs/settings + .hatfield/settings.yaml updates for ai.favorite_models.

## Task workflow update - 2026-05-18T22:15:06.106Z
- Validation: vendor/bin/phpunit tests/CodingAgent/Config/ModelSelectionServiceTest.php — 40 tests, 92 assertions, OK; vendor/bin/phpunit tests/Tui/Listener/ModelCommandHandlerTest.php — 18 tests, 49 assertions, OK; ~/.local/bin/castor test — 509 tests, 8453 assertions, OK (1 pre-existing notice); ~/.local/bin/castor deptrac — 0 violations; ~/.local/bin/castor phpstan — 0 errors; ~/.local/bin/castor cs-check — clean
- Summary: Follow-up fork applied review fixes on commit 3d5bca5d:
1) Fixed stale favorite state: added in-process $favRaw cache so toggleFavorite() is immediately visible to getFavoriteModels/isFavorite/getOrderedModels/cycleFavoriteModel without AppConfig rebuild.
2) Added 7 tests in ModelSelectionServiceTest (add/remove immediate visibility) + 3 tests in ModelCommandHandlerTest (fav list, star, add-remove immediacy).
3) Made ModelControlListener::register() idempotent with has()/setHandler() pattern.
4) Documented ai.favorite_models in .hatfield/settings.yaml and docs/settings.md.
Validation: 509 tests pass, 8453 assertions, 0 deptrac violations, 0 phpstan errors, cs-check clean.
- Applied review follow-up fixes: favorite in-process cache, immediate visibility tests, idempotent listener registration, settings docs. Commit 3d5bca5d.
