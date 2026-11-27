# Test coverage

Run coverage through Castor in the checkout being changed. Xdebug must be installed
in the PHP CLI used by Castor. The task enables `XDEBUG_MODE=coverage` for the test
process; no global PHP or user settings changes are needed.

```bash
castor test --coverage --filter=RunStateTest
castor test --coverage-paths --filter=RunStateTest
castor test --coverage --suite=agent-core
```

`--coverage` collects line coverage. `--coverage-paths` implies `--coverage` and
adds Xdebug branch/path coverage. Both use sequential PHPUnit, including when
only a suite is selected. Normal `castor test` and `castor check` remain unchanged.
Coverage reuses the existing isolated HOME, database/cache bootstrap, strict
PHPUnit checks, process ownership and timeout limits. Branch/path collection can
be expensive: prefer focused tests rather than increasing timeout budgets.

## Reports and failures

Castor prints the HTML report location in its run-scoped report directory under
`var/reports/`. Use the printed location, not a guessed latest directory.

- `coverage-html/index.html`: navigate to the affected class and method to inspect
  executed and missed lines; with `--coverage-paths`, inspect branches and paths.
- `coverage-clover.xml`: machine-readable line coverage and aggregate metrics.
  Use HTML for branch/path inspection; Clover is not a complete path report.
- `coverage.txt`: text summary for quick inspection and handoffs.
- `phpunit-filter.junit.xml` and `test-unit-filter.log`: test results and runner
  output, including failures and coverage warnings.

The source scope comes from `phpunit.xml.dist` (`src/`). A filtered test run still
reports against that source scope, so a low repository-wide percentage is expected
and does not establish whether the targeted behavior is covered. Extension source
outside that scope is not measured by these reports.

The task fails for missing Xdebug, an empty selected test suite, PHPUnit issues or
test failures, and missing/empty reports after an otherwise successful run. It does
not impose an arbitrary global percentage threshold. A successful command means
that tests and report generation passed, not that every relevant behavior is covered.
Reports produced by failing tests are diagnostic evidence, not passing QA.

## Coverage-first changes

1. Before production changes, map the behavior to methods, branches and existing
   test assertions. Run the relevant tests with coverage and inspect those methods.
2. Check assertions as well as executed lines: each required outcome, boundary,
   failure path and invariant needs meaningful proof. Line coverage alone cannot
   prove both outcomes of a conditional or the correctness of an assertion.
3. Add tests for gaps, rerun coverage, and confirm the previously missing paths are
   exercised before changing production code. For a bug, explicitly record a
   regression test that fails on the old behavior rather than calling it a pass.
4. After implementation, rerun the same tests and coverage, then the required QA.
   Compare the affected behavior and missed paths, not only aggregate percentages.
5. Record commands, report locations, relevant method/branch gaps and actual results
   in the task QA summary. Retain baseline evidence needed for comparison before
   normal report cleanup removes older runs.

Coverage measures instrumented PHPUnit execution. External application processes
are not automatically merged into the report; do not infer subprocess, controller
or TUI coverage from a parent test passing.
