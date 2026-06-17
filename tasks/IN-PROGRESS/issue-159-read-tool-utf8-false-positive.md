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
Status: IN-PROGRESS
Branch: task/issue-159-read-tool-utf8-false-positive
Worktree: /home/ineersa/projects/agent-core-worktrees/issue-159-read-tool-utf8-false-positive
Fork run: ectdn5pv1hz3
PR URL:
PR Status:
Started: 2026-06-17T21:49:35.275Z
Completed:

## Work log
- Created: 2026-06-17T21:37:51.588Z

## Task workflow update - 2026-06-17T21:48:33.522Z
- User clarified: keep UTF-8 enforcement, but fix validation. Consider whether the existing League/Flysystem MIME type detection library should be used where appropriate; do not rely on MIME detection as a substitute for validating text encoding.

## Task workflow update - 2026-06-17T21:49:35.275Z
- Moved TODO → IN-PROGRESS.
- Created branch task/issue-159-read-tool-utf8-false-positive.
- Created worktree /home/ineersa/projects/agent-core-worktrees/issue-159-read-tool-utf8-false-positive.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/issue-159-read-tool-utf8-false-positive.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/issue-159-read-tool-utf8-false-positive.
- Summary: User approved starting implementation for GitHub issue #159. Scope: fix `ReadFileTool` UTF-8 validation false positives on valid UTF-8 files while preserving binary/image/invalid-encoding rejections.

## Task workflow update - 2026-06-17T21:49:59.138Z
- Recorded fork run: iq5q6zujj5es
- Summary: Launched implementation fork in worktree `/home/ineersa/projects/agent-core-worktrees/issue-159-read-tool-utf8-false-positive`. Fork instructed to read testing skill + tests/AGENTS.md, fix `ReadFileTool` UTF-8 sample validation false positive while preserving binary/image/invalid-UTF-8 rejections, add regression tests, run focused Castor validation, and commit changes.

## Task workflow update - 2026-06-17T21:56:41.264Z
- Summary: Fork iq5q6zujj5es completed commit `2ea02eae2` and reported full validation passing, but parent verification found two issues before accepting handoff: (1) fork report claimed `readSample()` changed to `fopen(..., 'rb')`, but code still uses `'r'`; (2) the new trim-based UTF-8 fix can accept genuinely invalid UTF-8 if the invalid byte is in the last 1-3 bytes of the 8192-byte sample, because trimming can produce a valid prefix. Launching a narrow follow-up fork to revise the algorithm so it completes boundary-truncated sequences by reading/using a small lookahead instead of trimming invalid bytes away, and to add an invalid-boundary regression test.

## Task workflow update - 2026-06-17T21:57:05.018Z
- Recorded fork run: fd7nmzr5q9cy
- Summary: Launched follow-up fork `fd7nmzr5q9cy` to correct the first fix: replace trim-away-invalid-suffix behavior with lookahead/completion validation, switch sampling to binary mode if applicable, add invalid-boundary regression test, rerun focused Castor validation, and commit.

## Task workflow update - 2026-06-17T22:03:03.619Z
- Summary: Follow-up fork `fd7nmzr5q9cy` completed commit `31238b612`, but parent verification found remaining blockers before accepting: (1) `readSample()` still uses `fopen($resolvedPath, 'r')` despite handoff claiming binary mode; (2) `isSampleValidUtf8()` can reject valid files when bytes 1-8192 are valid but the 3-byte lookahead starts a 4-byte character at byte 8193 because it never checks the 8192-byte prefix; (3) it can accept invalid files <=8192 bytes ending in stray continuation bytes by trimming them at EOF. Need a narrow correction: for samples <=8192 bytes validate the full sample only; for samples >8192 bytes accept if any prefix length 8192..totalLen is valid, never trim below 8192.

## Task workflow update - 2026-06-17T22:03:30.442Z
- Recorded fork run: 7i1fjqzsiuii
- Summary: Launched second follow-up fork `7i1fjqzsiuii` to fix remaining UTF-8 sampling blockers: switch `readSample()` to binary mode, simplify validation to full-sample check for <=8192 and valid-prefix search for 8192..8195 when lookahead exists, add regressions for valid 8192-byte prefix with incomplete lookahead and invalid EOF continuation byte, run focused Castor validation, and commit.

## Task workflow update - 2026-06-17T22:06:17.674Z
- Summary: User raised UTF-8 BOM/text-detection question during implementation. Review/acceptance consideration: UTF-8 BOM (`EF BB BF`, U+FEFF) is valid UTF-8 and should be accepted by the read tool; add/verify regression coverage if not already present. UTF-16/UTF-32 BOMs should still be rejected as non-UTF-8 or non-text for this tool. MIME/type detection is useful for binary/image/document rejection but cannot replace explicit UTF-8 validation.

## Task workflow update - 2026-06-17T22:08:54.635Z
- Summary: Fork `7i1fjqzsiuii` completed commit `5b9be66a0` and most algorithmic blockers appear resolved, but parent verification still found one required mismatch: `readSample()` still has `@fopen($resolvedPath, 'r')` even though the handoff and task requirements say binary mode (`'rb'`). User also asked about UTF-8 BOM handling; add/verify a UTF-8 BOM regression while making the narrow binary-mode correction.

## Task workflow update - 2026-06-17T22:09:13.470Z
- Recorded fork run: ectdn5pv1hz3
- Summary: Launched tiny corrective fork `ectdn5pv1hz3` to change `readSample()` from `fopen(..., 'r')` to `fopen(..., 'rb')`, add a UTF-8 BOM acceptance regression test, run focused Castor validation, and commit.

## Task workflow update - 2026-06-17T22:20:20.386Z
- Summary: Clarification: plain 7-bit ASCII is valid UTF-8 and should work. "Extended ASCII" is ambiguous and is not generally a subset of UTF-8; Latin-1/Windows-1252/CP437 bytes in the 0x80-0xFF range should remain rejected unless encoded as valid UTF-8. Do not add transcoding/encoding-detection support in this bug fix unless explicitly requested.
