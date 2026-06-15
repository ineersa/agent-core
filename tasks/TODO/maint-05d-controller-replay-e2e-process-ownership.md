# MAINT-05D Controller replay E2E and explicit process ownership

## Goal
## Context

Fourth stage of the cardinal QA/test rework. Port controller/runtime E2E away from routine live LLM calls and make subprocess ownership explicit so failed tests do not leave messenger/controller consumers behind.

Current problem:
- Controller E2E spawns a controller process that can spawn messenger consumers.
- Failed or killed tests can leave consumers/orphans around.
- Tests currently depend on live llama.cpp for routine behavior proof.

Dependencies:
- Prefer after MAINT-05C LLM replay foundation.
- Castor command wiring should follow MAINT-05A.

Known entrypoints:
- `tests/CodingAgent/Runtime/Controller/E2E/ControllerE2eTestCase.php`
- `tests/CodingAgent/Runtime/Controller/E2E/ControllerSmokeTest.php`
- `tests/CodingAgent/Runtime/Controller/E2E/WriteFileToolE2eTest.php`
- `tests/CodingAgent/Runtime/Controller/E2E/ViewImageToolE2eTest.php`
- `tests/CodingAgent/Runtime/Controller/E2E/OutputCapReadFileControllerTest.php`
- runtime/controller/messenger process spawning code under `src/CodingAgent/Runtime/` and CLI command wiring.

## Acceptance criteria
- Controller/runtime E2E has a deterministic replay mode and default automated tests use replay rather than live llama.cpp.
- At least one controller replay E2E proves a realistic tool-call flow using replay fixtures from MAINT-05C.
- Controller process ownership is explicit: parent controller, messenger consumers, and any child process groups have a teardown contract that runs on success and failure.
- Failed controller E2E tests do not rely on broad stale-killer cleanup to remove consumers. Orphan prevention is designed into the harness/process manager.
- Controller E2E tests use targeted event proof helpers, not broad sleeps or full-run waits unless full-run completion is the behavior under test.
- Live controller/LLM smoke remains available as opt-in validation but is not part of default deterministic QA.
- Validation uses Castor only: controller replay E2E command, relevant unit/integration tests, `castor deptrac`, `castor phpstan`, `castor cs-check`, and opt-in live smoke if prerequisites are available.
- Task handoff records process tree behavior, cleanup proof, and before/after live LLM usage in controller tests.

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
- Created: 2026-06-15T21:07:41.944Z
