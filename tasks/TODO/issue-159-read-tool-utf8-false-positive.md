# Fix issue #159: read tool false non-UTF-8 errors on valid UTF-8 files

## Goal
GitHub issue: https://github.com/ineersa/agent-core/issues/159

Problem: `read` tool can throw `Cannot read "...": file contains non-UTF-8 encoded content` for files reported as valid UTF-8 by `file` and readable via `cat` (reported with `docs/tui-architecture.md` and `docs/async-runtime-architecture.md` from the `task/issue-131-tui-tool-result-output` worktree).

Scout report highlights:
- Primary implementation area: `src/CodingAgent/Tool/ReadFileTool.php`.
- Relevant methods: `validateTarget()` checks a sample with `mb_check_encoding($sample, 'UTF-8')`; `readSample()` reads exactly 8192 bytes with `fopen($resolvedPath, 'r')` + `fread($fh, 8192)`.
- Most plausible root cause to verify: the fixed 8192-byte sample can end inside a multi-byte UTF-8 sequence, so `mb_check_encoding()` rejects a valid file because the sampled prefix is incomplete.
- Also check binary/text MIME detection ordering, output cap/truncation interactions, control/ANSI bytes, and whether session/tool-result payload corruption differs from actual file contents.
- Existing tests: `tests/CodingAgent/Tool/ReadFileToolTest.php` already covers truly invalid UTF-8 via `testReadNonUtf8FileThrows()` but lacks valid UTF-8 boundary cases.

Implementation notes:
- Preserve rejection of genuinely invalid UTF-8 and binary files.
- Avoid compatibility shims; replace the faulty validation behavior with a clear, current implementation.
- Follow `tests/AGENTS.md`: load the testing skill, use shared test isolation helpers where adding/updating tests, and run all QA through Castor only.

## Acceptance criteria
- Valid UTF-8 files are accepted even when the first inspection sample ends at or near a multi-byte character boundary.
- `read` still rejects genuinely invalid non-UTF-8 content without null bytes and still rejects binary/image files with the intended diagnostic/hint behavior.
- Regression tests cover valid UTF-8 with multi-byte content, including an 8192-byte sample-boundary case representative of docs/TUI markdown content.
- Implementation fork records that `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` were read before test changes.
- Focused validation passes at minimum: `castor test --filter=ReadFileToolTest`, `castor phpstan src/CodingAgent/Tool/ReadFileTool.php`, `castor cs-check`, and `castor deptrac`. Run `castor check` before CODE-REVIEW because this is an LLM-visible tool/runtime path.

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
- Created: 2026-06-17T21:37:51.588Z
