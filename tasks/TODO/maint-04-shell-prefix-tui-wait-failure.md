# MAINT-04 Fix ShellPrefixSmokeTest post-merge TUI wait failure

## Goal
Post-merge validation for MAINT-02 failed only on `castor check` step `test:tui`.

Failure:
- `Ineersa\Tui\Tests\E2E\ShellPrefixSmokeTest::testFirstInputShellLsShowsOutputAndAllowsNextPrompt`
- `RuntimeException: Timed out after 2.0s waiting for needle "✕" in pane %152`
- Log: `var/reports/check-test:tui.log`
- The last capture showed the shell command output succeeded and the next normal prompt was submitted, but the UI was still in `◐ Working...` after about 9s:
  - `❯ !ls -1`
  - `● home`
  - `shell-e2e-marker-5ff2c129.txt`
  - `❯ Say exactly: hello`
  - `◐ Working...`

Likely introduced/exposed by MAINT-02 reducing broad TUI waits. Need investigate whether the test should wait for an assistant response, error block, or completion marker rather than a 2s `✕` fallback, and whether the shell-prefix flow is hanging under the deterministic test model.

## Acceptance criteria
- `castor test:tui --filter=ShellPrefixSmokeTest` passes repeatedly on deterministic llama.cpp test model.
- Full `castor test:tui` passes.
- Full `LLM_MODE=true castor check` passes post-fix or a precise external blocker is recorded.
- No broad 30-60s sleeps are reintroduced; waits target exact visible/runtime proof with sane short caps.
- Failure diagnostics are improved if the shell-prefix flow hangs again.

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
- Created: 2026-06-11T17:01:38.245Z
