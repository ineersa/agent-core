# TOOLS-07 Implement read tool with cat -n line numbers

## Goal
Implement the text `read` tool with original line numbers.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-00 (`CancellableProcessRunner`, `CancellationGuard`).
- Depends on TOOLS-01 (`PathResolver`).
- Depends on TOOLS-02 (`OutputCap`).

Scope:
- Replace/complete `src/CodingAgent/Tool/ReadFileTool.php`.
- Register with `#[AsTool('read', description: 'Read file contents with cat -n line numbering')]`.
- Schema should be derived from `__invoke(string $path, ?int $offset = null, ?int $limit = null)`.
- Resolve path with `PathResolver`.
- Do not handle images here; image files belong to `view_image`.
- Use Unix tools through `CancellableProcessRunner` so output matches `cat -n` format, preserves original line numbers, and honors cancellation/timeout:
  - full/default read: `cat -n "$path" | head -2000`
  - offset+limit: `cat -n "$path" | sed -n '${offset},${end}p'`
  - offset only: `cat -n "$path" | sed -n '${offset},$p'`
- Build commands safely. Prefer Process array with shell only where needed; quote paths with `escapeshellarg` if using `bash -lc`.
- Check cancellation before starting shell commands and rely on `CancellableProcessRunner` while commands execute.
- Reject obvious device paths (`/dev/*`, `/proc/*/fd/*`) and binary/non-UTF-8 content with clear errors.
- Pass text through `OutputCap`.
- Include continuation hint when output is truncated by line/limit.
- Add focused tests.

Out of scope:
- No PHP LineFormatter.
- No image/PDF/notebook handling.
- No dedup cache.

## Acceptance criteria
- `read` tool is discoverable through Symfony AI toolbox metadata.
- Output uses `cat -n` style with original file line numbers, including offset reads.
- Offset and limit are 1-indexed and validated.
- Large output is capped/persisted through `OutputCap`.
- Cancellation while the read subprocess is running terminates promptly and returns the standard cancellation path.
- Binary/device paths are rejected with clear messages.
- Tests cover full read, offset+limit, offset-only, missing file, binary rejection, and cap integration.
- Focused tests pass with Castor/PHPUnit.

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
- Created: 2026-05-17T04:42:49.755Z
