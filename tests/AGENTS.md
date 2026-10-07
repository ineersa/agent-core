# Tests

- Load `.agents/skills/testing/SKILL.md` before writing, reviewing, debugging, or running tests.
- Keep one test file per production class. Put shared helpers under `tests/*/Support/`.
- Before adding fixtures or doubles, read the testing skill's `references/helpers.md`; reuse existing isolation, bus, logger, and kernel helpers.
- For controller or TUI process tests, read `references/e2e.md` in that skill before changing the harness.
- Never read or write real project sessions from tests; isolate under `var/tmp/test-{uuid}`.
