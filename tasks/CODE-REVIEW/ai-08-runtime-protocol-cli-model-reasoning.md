# AI-08 Add runtime protocol and CLI inputs for model/reasoning

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-08--add-runtime-protocol-and-cli-inputs-for-modelreasoning

Goal: allow initial model/reasoning to enter the system from CLI/TUI/process clients.

Depends on: AI-01.

Parallelism: can run alongside AI-02 and AI-03 after AI-01; unblocks AI-10 and AI-14.

Scope:
- Extend `StartRunRequest` with optional `model` and `reasoning` fields.
- Extend JSONL protocol payloads for process runtime.
- Update `AgentCommand` CLI options: `--model`, `--reasoning`.
- Update `InteractiveMode`, `SessionInitializer`, `SubmitListener`, `InProcessAgentSessionClient`, and `JsonlProcessAgentSessionClient` as needed to preserve and forward fields.
- Keep backward compatibility when fields are absent.

## Acceptance criteria
- Headless and TUI starts can pass model/reasoning.
- Existing start-run call sites compile and work with null fields.
- JSONL clients ignore/omit absent fields safely.
- Suggested validation: `castor test --filter Runtime`; `castor deptrac`.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/ai-08-runtime-protocol-cli-model-reasoning
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-08-runtime-protocol-cli-model-reasoning
Fork run: a75hrv2dqf1k
PR URL: https://github.com/ineersa/agent-core/pull/8
PR Status: open
Started: 2026-05-16T22:30:54.786Z
Completed:

## Work log
- Created: 2026-05-16T22:02:34.212Z

## Task workflow update - 2026-05-16T22:30:54.786Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-08-runtime-protocol-cli-model-reasoning.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-08-runtime-protocol-cli-model-reasoning.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-08-runtime-protocol-cli-model-reasoning.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-08-runtime-protocol-cli-model-reasoning.
- Summary: Starting Batch B task AI-08 after AI-01 completion: runtime protocol and CLI inputs for model/reasoning.

## Task workflow update - 2026-05-16T22:32:11.151Z
- Recorded fork run: a75hrv2dqf1k
- Summary: Launched implementation fork a75hrv2dqf1k in worktree /home/ineersa/projects/agent-core-worktrees/ai-08-runtime-protocol-cli-model-reasoning.

## Task workflow update - 2026-05-16T22:43:23.696Z
- Validation: AI-08 worktree: `castor test` before changes passed: 234 tests, 7773 assertions, 1 notice.; AI-08 worktree: `castor test` after changes passed: 234 tests, 7773 assertions, 1 notice.; AI-08 worktree: `castor deptrac` passed: 0 violations, 323 allowed, 69 uncovered.; AI-08 worktree: `castor phpstan` initially found expected dead property for RunMetadata::$reasoning; after baseline update, passed with no errors.; AI-08 worktree: `castor check` passed: deptrac + phpunit + phpstan + cs-fixer.; AI-08 worktree: `php bin/console agent --help` shows `--model` and `--reasoning` options.
- Summary: AI-08 implementation complete in worktree commit b1ed50aa. Added optional model/reasoning plumbing through StartRunRequest, RunMetadata, AgentCommand CLI/headless JSONL handling, InProcessAgentSessionClient, JsonlProcessAgentSessionClient, and SessionInitializer. Added phpstan baseline entry for RunMetadata::$reasoning until AI-10 consumes it. No model selection/provider routing implemented.

## Task workflow update - 2026-05-16T22:43:41.274Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-08-runtime-protocol-cli-model-reasoning to origin.
- branch 'task/ai-08-runtime-protocol-cli-model-reasoning' set up to track 'origin/task/ai-08-runtime-protocol-cli-model-reasoning'.
- Created PR: https://github.com/ineersa/agent-core/pull/8
- Validation: `castor test` passed after changes: 234 tests, 7773 assertions, 1 notice.; `castor deptrac` passed: 0 violations.; `castor phpstan` passed after adding expected temporary baseline for RunMetadata::$reasoning until AI-10 reads it.; `castor check` passed.; `php bin/console agent --help` shows `--model` and `--reasoning` options.
- Summary: AI-08 ready for review. Worktree branch committed at b1ed50aa with runtime protocol/CLI plumbing for optional model and reasoning fields. No model selection or provider routing behavior included.
