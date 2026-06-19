# Hashline Read/Edit Implementation Plan

## 1. Goal

Replace Hatfield's patch-based text editing workflow with a hashline-anchored read/edit protocol modeled on `/home/ineersa/claw/pi-hashline-edit`.

The target UX is:

1. `read` returns every text line as `LINE#HH:content`.
2. `edit` accepts structured operations anchored to those exact `LINE#HH` refs.
3. The engine validates anchors against the current file before writing.
4. Stale anchors fail with actionable retry context, not fuzzy relocation.
5. Successful edits return fresh anchors for the changed region so the model can chain nearby edits without a full re-read.

This should replace the current GNU unified-diff patch interface, not coexist with it. Hatfield is in active development and project rules explicitly avoid compatibility shims unless requested.

Reference implementation:

- `/home/ineersa/claw/pi-hashline-edit`
- key files:
  - `src/hashline.ts` — core hash/parse/validate/apply engine
  - `src/read.ts` — hashline read output
  - `src/edit.ts` — edit schema and execution pipeline
  - `src/edit-response.ts` — success/noop responses and host-only metrics
  - `src/fs-write.ts` — atomic write behavior
  - `docs/adr/0001-keep-two-character-hashlines.md`
  - `docs/adr/0002-delete-edit-return-payload-modes.md`

## 1.1 Decisions from planning discussion

- Use native seeded xxHash32 support for line hashes. Do not silently fall back to another algorithm; if the runtime lacks the required native algorithm/seed support, fail fast with a user-visible tool/TUI error. Future bundled-PHP builds must include this capability.
- Keep Hatfield's current invalid UTF-8 behavior for v1: reject invalid UTF-8 instead of lossy `U+FFFD` read/rewrite.
- Match pi-level filesystem write semantics in v1, including symlink target updates, hard-link preservation, permissions, exclusive temp creation, and cleanup.
- Return pi-style model-visible text plus host-only structured details: diff, warnings, classification, metrics, and snapshot/fingerprint where supported by Hatfield's tool result path.
- Prefer extending an existing deterministic tool-call replay fixture for proof; create a new fixture only if extending an existing one becomes awkward or unclear.
- Use Symfony Lock around the full edit critical section even though `edit` is sequential/non-parallelable, so external processes cannot race validation vs write within Hatfield's control surface.
- Reject files with individual lines too large to hash/display safely; do not truncate a line and emit a bogus anchor.
- Prefer Symfony String utilities for Unicode-aware normalization/comparison paths, while keeping byte-level file reconstruction consistent and tested.

## 2. Non-goals

Do not port the older `oh-my-pi` free-form mini-language (`SWAP`, `DEL`, `INS.*`) for Hatfield v1.

Do not add:

- unified-diff compatibility mode;
- dual `read` formats;
- automatic stale-anchor relocation;
- snapshot IDs in model-visible text;
- block/tree-sitter operations;
- recovery/3-way-merge subsystem.

Those can be revisited only if there is concrete evidence that strict line-anchor retry is insufficient.

## 3. Public tool contract

### 3.1 `read`

Current Hatfield read output uses `cat -n`-style lines. Replace it with hashlines:

```text
LINE#HH:content
```

Examples:

```text
 8#MQ:        return $value;
 9#VR:    }
10#WS:
```

Rules:

- `LINE` is 1-indexed original file line number.
- `HH` is a 2-character custom-alphabet hash.
- `:` separates anchor from literal content.
- Line numbers may be left-padded to the widest visible line number in the page.
- Terminal newline sentinel is hidden; a file ending with `\n` does not show an extra blank final line.
- Empty file returns advisory text, not synthetic anchors:
  - `File is empty. Use edit with prepend or append and omit pos to insert content.`
- Offset beyond EOF returns an explicit EOF message with total line count.
- Partial reads keep continuation hints, updated for hashline output.

### 3.2 `edit`

Replace current schema:

```json
{ "path": "...", "patch": "..." }
```

with:

```json
{
  "path": "src/Foo.php",
  "edits": [
    {
      "op": "replace",
      "pos": "12#MQ",
      "end": "15#VR",
      "lines": ["new literal content"]
    }
  ]
}
```

Supported operations:

| op | Required fields | Meaning |
|---|---|---|
| `replace` | `pos`, `lines` | Replace exactly the line at `pos`. |
| `replace` range | `pos`, `end`, `lines` | Replace inclusive range `pos..end`. |
| `append` | `lines`; optional `pos` | Insert after `pos`, or EOF when `pos` omitted. |
| `prepend` | `lines`; optional `pos` | Insert before `pos`, or BOF when `pos` omitted. |
| `replace_text` | `oldText`, `newText` | Replace one exact unique text occurrence. Prefer anchors. |

Field rules:

- Every edit item requires `op`.
- `replace` requires `pos` and `lines`; `end` is optional.
- `append`/`prepend` require non-empty `lines`; `pos` is optional.
- `replace_text` requires `oldText` and `newText`; anchor/`lines` fields are invalid.
- `lines` is literal file content only: no anchor prefixes, no diff markers, no copied `LINE#HH:` display rows.
- Multiple edits in one call validate against the same pre-edit snapshot and are applied back-to-front.
- Overlapping or touching edits must be merged by the model; the engine rejects conflicts.

### 3.3 Success response

Model-visible success output should be small and directly reusable:

```text
--- Anchors A-B ---
A#HH:...
B#HH:...
```

Rules:

- Return changed lines plus small context, default 2 surrounding lines.
- Cap returned anchor block, e.g. max 12 lines and 50 KiB.
- If too large or changed range cannot be bounded:
  - `Anchors omitted; use read for subsequent edits.`
- Warnings appear after the anchor block.
- Diff, metrics, and snapshot/fingerprint data are host-only details, not model-visible text.

### 3.4 Noop response

If edits produce identical content:

```text
No changes made to path
Classification: noop
...
```

Noop should not rewrite the file. It may include diagnostics for identical replacement spans and warnings.

## 4. Hash algorithm

Port the pi extension algorithm exactly.

Constants:

```php
private const HASH_ALPHABET = 'ZPMQVRWSNKTXJBYH';
```

Properties:

- 2-character hash.
- Custom 16-char alphabet excludes hex digits, vowels, and visually-confusable letters.
- Hash byte maps to two alphabet nibbles.
- Uses xxHash32 and keeps the low byte.
- Strips `\r` and trims trailing whitespace before hashing.
- Symbol-only lines seed hash with the line number; alphanumeric lines use seed `0`.

Reference TypeScript:

```ts
const NIBBLE_STR = "ZPMQVRWSNKTXJBYH";
const RE_SIGNIFICANT = /[\p{L}\p{N}]/u;

export function computeLineHash(idx: number, line: string): string {
    line = line.replace(/\r/g, "").trimEnd();
    let seed = 0;
    if (!RE_SIGNIFICANT.test(line)) {
        seed = idx;
    }
    return DICT[xxh32(line, seed) & 0xff];
}
```

PHP hashing decision:

- Require native seeded xxHash32 support so punctuation/blank-line hashes match the pi reference exactly.
- PHP's hash extension exposes `hash('xxh32', $data, binary: true, options: ['seed' => $seed])`; implementation should use this API and verify `xxh32` appears in `hash_algos()` before hashing.
- Convert the native digest to the same low byte used by pi (`xxHash32(...) & 0xff`) and then map that byte through `HASH_ALPHABET`. Tests must lock down byte/endianness handling.
- Do not silently switch algorithms or add a non-native fallback in v1. If native seeded xxHash32 is unavailable in the project runtime/container, throw a clear user-visible error from the tool so it appears in the TUI; future bundled-PHP builds must ship with the required algorithm support.
- Hashes must match the reference for known vectors.

Known vector generation should be done from the cloned reference implementation and committed as PHP tests.

## 5. Anchor grammar and diagnostics

Accepted input references:

```text
10#MQ
10 # MQ
10#MQ:content hint
>>> 10#MQ:current line
+10#MQ
-10#MQ
```

Parsing rules:

- Strip leading whitespace and optional display/diff marker characters `>`, `+`, `-`.
- Parse `DIGITS # HASH`.
- Optional `:...` suffix becomes `textHint`.
- `line >= 1`.
- `hash` length is exactly 2.
- hash chars must all be from `ZPMQVRWSNKTXJBYH`.

Reject with specific bracketed error codes:

| Code | Condition |
|---|---|
| `[E_BAD_REF]` | Malformed anchor, missing hash, bad separator, invalid hash chars. |
| `[E_RANGE_OOB]` | Anchor line does not exist in current file. |
| `[E_STALE_ANCHOR]` | Current line hash does not match anchor hash. |
| `[E_BAD_OP]` | Invalid edit shape or field combination. |
| `[E_INVALID_PATCH]` | Payload contains hashline display prefixes or diff rows. |
| `[E_EDIT_CONFLICT]` | Multi-edit spans overlap/touch incompatibly. |
| `[E_MULTI_MATCH]` | `replace_text` matched more than once. |
| `[E_NO_MATCH]` | `replace_text` matched zero times. |
| `[E_WOULD_EMPTY]` | Edit would empty a non-empty file. |

Stale-anchor errors must include current retry lines:

```text
[E_STALE_ANCHOR] 1 stale anchor. Retry with the >>> LINE#HASH lines below.
Stale refs: 12#MQ

   10#VR:...
   11#TX:...
>>>12#NK:current content
   13#ZP:...
```

For range replacements, include both range endpoints when either endpoint is stale.

## 6. Fuzzy validation and warnings

Keep strict validation, with only the pi extension's limited fuzzy path:

- If an anchor has `textHint`, and the hash matches the hinted text, and the current line is equivalent after Unicode/whitespace normalization, accept it with warning.
- Normalize curly single quotes, curly double quotes, Unicode hyphens, and Unicode spaces.
- Do not trust arbitrary `textHint` when the hash is not valid for that hint.

Warnings, not hard failures:

- accepted fuzzy anchor;
- bare `HH:` prefix in payload when it matches current file hashes or looks suspicious;
- single-anchor replace with multi-line payload;
- replacement first/last line matches adjacent surviving line after trim;
- suspicious `\uDDDD` placeholder text.

Hard failures:

- `LINE#HH:` copied into `lines`;
- `+LINE#HH:` copied into `lines`;
- diff-style removed rows such as `-  10    old content`.

## 7. Internal architecture

Keep this implementation in the CodingAgent tool layer. Hashline editing is tool/runtime behavior, not AgentCore domain. Avoid adding a new AgentCore layer unless a future non-tool consumer appears.

Proposed files:

```text
src/CodingAgent/Tool/Hashline/
  HashlineAnchorDTO.php
  HashlineEditOperationDTO.php
  HashlineEditOperationTypeEnum.php
  HashlineMismatchDTO.php
  HashlineNoopEditDTO.php
  HashlineResolvedSpanDTO.php
  HashlineApplyResultDTO.php
  LineHashComputer.php
  HashlineAnchorParser.php
  HashlineFormatter.php
  HashlineFileBuffer.php
  HashlineEditValidator.php
  HashlineEditSpanResolver.php
  HashlineEditApplier.php
  HashlineChangedRangeCalculator.php
  HashlineEditResponseBuilder.php
  LinkPreservingTextFileWriter.php
```

Existing files to modify:

```text
src/CodingAgent/Tool/ReadFileTool.php
src/CodingAgent/Tool/EditFileTool.php
```

Current tool registration pattern remains:

- `EditFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface`
- `ReadFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface`
- `ToolDefinitionDTO` supplies schema, prompt line, prompt guidelines, and sequential execution mode.

### 7.1 Class responsibilities

`LineHashComputer`

- Owns `HASH_ALPHABET`.
- Computes `LINE#HH` hash values.
- Provides `isValidHash(string $hash): bool`.
- Provides vector-stable behavior matching pi reference.

`HashlineAnchorParser`

- Parses model-supplied anchor refs.
- Produces `HashlineAnchorDTO`.
- Emits precise `[E_BAD_REF]` messages.

`HashlineFormatter`

- Formats read pages and post-edit anchor regions.
- Handles line-number padding.
- Hides terminal newline sentinel.
- Does not do file I/O.

`HashlineFileBuffer`

- Normalized LF view of a text file.
- Tracks original line ending style and BOM.
- Maps line numbers to byte/character spans.
- Provides visible lines excluding terminal sentinel.

`HashlineEditValidator`

- Validates edit-item shape.
- Validates anchor hashes against current file lines.
- Rejects display prefixes and diff leakage in payloads.
- Produces warnings and mismatches.

`HashlineEditSpanResolver`

- Converts operations into character spans.
- Deduplicates identical spans.
- Rejects overlap/touch conflicts.
- Sorts spans back-to-front.

`HashlineEditApplier`

- Pure orchestration:
  1. build `HashlineFileBuffer`;
  2. validate anchors;
  3. warn on suspicious payloads;
  4. resolve spans;
  5. assemble result;
  6. prevent emptying non-empty file;
  7. compute changed range.

`HashlineChangedRangeCalculator`

- Computes first/last changed line in final document coordinates.
- Used by response builder for fresh anchors.

`HashlineEditResponseBuilder`

- Builds model-visible changed/noop responses.
- Builds host-only details: diff, metrics, warnings, classification.

`LinkPreservingTextFileWriter`

- Replaces current patch temp-output write path with explicit link-preserving text writes.
- Hatfield intentionally edits existing files in place to preserve filesystem identity instead of replacing them with a new inode.
- Existing regular files, symlink targets, and hard-linked files are updated in place; permissions/ownership/link relationships remain intact.
- New files are created with exclusive create and safe permissions.
- Use `flock()`/Symfony Lock where appropriate, write all bytes with retry/error checks, truncate to the final length, flush, and call `fsync()` when available.
- Surface a clear error if writing fails; do not claim rollback guarantees for in-place writes.

## 8. Edit application semantics

Pipeline:

```text
raw request
  -> validate root envelope
  -> parse edit operations / anchors
  -> resolve target path and verify text file
  -> normalize BOM + line endings to LF in memory
  -> acquire Symfony Lock for the resolved mutation target
  -> validate all anchors against pre-edit file
  -> reject conflicts
  -> apply spans back-to-front
  -> restore original line endings + BOM
  -> link-preserving write
  -> release lock
  -> return fresh changed-region anchors
```

Line operation semantics:

- `replace` single line: replace the exact line's content, preserving overall file newline policy.
- `replace` range: replace inclusive start/end lines.
- `replace` with `lines: []`: deletion.
- `append` with `pos`: insert after that line.
- `append` without `pos`: insert at EOF.
- `prepend` with `pos`: insert before that line.
- `prepend` without `pos`: insert at BOF.
- `replace_text`: exact unique text replacement in normalized content; preserve original line endings on final write.

Conflict semantics:

- duplicate identical spans are noops/deduped;
- two inserts at same boundary conflict;
- overlapping replace spans conflict;
- insert inside a replaced range conflicts;
- adjacent replacement ranges should be rejected as touching edits unless represented as one range.

Unicode/string handling: use Symfony String (`UnicodeString`/string helpers) for Unicode-aware fuzzy normalization and comparisons where helpful. Keep file offsets and reconstruction internally consistent: if spans are byte offsets, compute and slice with byte-string functions only; if character offsets are used, use Symfony String throughout that path. Tests must cover Unicode content.

## 9. Read tool changes

Current file:

```text
src/CodingAgent/Tool/ReadFileTool.php
```

Current behavior:

- validates path;
- rejects directories/binary/images;
- uses `cat -n` through a bash pipeline;
- supports `offset`/`limit`;
- appends continuation hints.

Target behavior:

- keep path, target, image/binary, extension, and UTF-8 guardrails;
- replace shell-based `cat -n` formatting with PHP-native line reading/formatting;
- return `LINE#HH:content`;
- keep default limit policy;
- keep output cap behavior via existing result processors;
- keep current Hatfield invalid UTF-8 rejection behavior for v1; do not add lossy `U+FFFD` read/rewrite semantics.
- reject files containing an individual line that exceeds the configured safe line preview/hash limit; do not emit partial-line anchors.

Provider prompt line should explain anchors clearly:

```text
Read a text file. Every line returns as LINE#HASH:content; copy those anchors verbatim into edit.
```

Prompt guidelines:

- Anchors are opaque; never compute or alter them.
- Use `offset` to continue when truncated.
- Re-read after an edit unless using fresh anchors returned by edit.

## 10. Edit tool changes

Current file:

```text
src/CodingAgent/Tool/EditFileTool.php
```

Current behavior:

- schema: `path`, `patch`;
- validates unified diff string;
- runs GNU `patch` dry-run and apply;
- returns additions/deletions summary.

Target behavior:

- schema: `path`, `edits` array;
- no GNU `patch` subprocess;
- use hashline engine to validate/apply;
- write through `LinkPreservingTextFileWriter`;
- return anchor block/noop response;
- keep `ToolRuntime::run()` cancellation checkpoints;
- keep `ToolExecutionMode::Sequential`.

Provider description should be close to pi extension prompt:

```text
Patch a text file at LINE#HASH anchors copied verbatim from read.
```

Prompt guidelines:

- Batch all edits to one file into a single call.
- `lines` is literal final content; never include copied `LINE#HASH:` prefixes or diff markers.
- Anchors are opaque; copy exactly from `read` or from the previous `edit` anchor block.
- Edits in one call must not overlap or touch; merge adjacent changes into one range.
- On `[E_STALE_ANCHOR]`, retry with the `>>>` fresh anchors from the error.

## 11. Tool result/details shape

Hatfield should return the same conceptual split as `pi-hashline-edit`: small model-visible text plus host-only structured details.

Implementation should verify the existing `RegistryBackedToolbox` / `ToolExecutor` result path can carry structured details without leaking them into model-visible text. If a small result-model adjustment is needed, include it in this task rather than deferring details.

Host-only details should include:

- unified diff string;
- first changed line where available;
- snapshot/fingerprint where available;
- classification: `applied` or `noop`;
- warnings list;
- metrics: edits attempted, noop edits, warnings count, changed line range, added lines, removed lines.

Minimum v1 model-visible text:

- applied: anchor block + warnings;
- noop: classification + noop details + warnings;
- error: exception message with bracketed code and retry instructions.

## 12. Filesystem and text handling

Preserve or explicitly document current Hatfield behavior for:

- path resolution through `PathResolver`;
- blocked special paths;
- image/binary rejection;
- UTF-8 validation;
- cancellation via `ToolRuntime`;
- output capping via existing processors.

Line endings:

- Normalize to LF internally.
- Detect original dominant line ending (`\n` vs `\r\n`).
- Restore original line ending style on write.
- Preserve BOM if present.

Link-preserving writes:

- Avoid external patch binary.
- Use Symfony Lock around read/validate/apply/write for the resolved mutation target.
- For existing files: edit in place rather than temp+rename, preserving inode identity, symlinks, hard links, permissions, and ownership.
- For symlinks: resolve the mutation target and update target contents while preserving the symlink itself.
- For hard links: preserve inode/link relationships by writing in-place; sibling hard links continue to observe the updated content.
- For new files: use exclusive create with safe permissions.
- Use `flock()` where appropriate, write all bytes with error checking, truncate to the final size, flush, and call `fsync()` when available.
- This deliberately prioritizes link/filesystem identity preservation over rename-style atomic replacement. A crash or SIGKILL mid-write can theoretically leave partial content; Symfony Lock protects Hatfield concurrency but not power loss or hostile external writers.
- Reject files with individual lines over the safe hashline limit instead of truncating line content.

## 13. Implementation phases

### Phase 1 — Pure hashline engine

Add `src/CodingAgent/Tool/Hashline/*` pure classes.

Deliverables:

- hash algorithm matches reference vectors;
- anchor parser;
- formatter;
- edit operation DTOs;
- validation errors/warnings;
- span resolver;
- changed range calculator;
- applier tests.

No tool behavior changes yet except test-only direct class use.

### Phase 2 — Replace `read` output

Modify `ReadFileTool` to use `HashlineFormatter`.

Deliverables:

- read output is `LINE#HH:content`;
- offset/limit behavior preserved;
- continuation hints updated;
- empty/OOB behavior explicit;
- prompt line/guidelines updated.

### Phase 3 — Replace `edit` schema and implementation

Modify `EditFileTool` in place.

Deliverables:

- remove `patch` schema;
- remove GNU patch application path;
- add `edits` schema;
- use `HashlineEditApplier`;
- atomic write final result;
- success/noop/stale responses;
- prompt line/guidelines updated.

### Phase 4 — Replay/controller proof

Extend an existing deterministic tool-call replay fixture where practical so the runtime/tool-call path proves:

1. model reads hashline anchors;
2. model calls `edit` with `edits` array;
3. file content changes;
4. edit response includes fresh anchors.

No TUI-specific E2E is required unless this task changes TUI interaction/rendering behavior. `castor check` still exercises replay-backed controller/TUI gates as required for LLM-visible flow.

### Phase 5 — Optional later hardening

Only after v1 lands:

- no-op loop guard;
- stale recovery via explicit re-read/retry helpers;
- block-aware operations.

## 14. Test strategy

Per project instructions, before writing/running tests the implementor must load:

- `.agents/skills/testing/SKILL.md`
- `tests/AGENTS.md`

All QA must go through Castor.

Test thesis:

> Hashline anchoring prevents stale or shifted line numbers from mutating the wrong file region; failures must be safe, precise, and recoverable by the model.

### 14.1 Unit tests

Proposed files:

```text
tests/CodingAgent/Tool/Hashline/LineHashComputerTest.php
tests/CodingAgent/Tool/Hashline/HashlineAnchorParserTest.php
tests/CodingAgent/Tool/Hashline/HashlineFormatterTest.php
tests/CodingAgent/Tool/Hashline/HashlineEditApplierTest.php
tests/CodingAgent/Tool/Hashline/HashlineChangedRangeCalculatorTest.php
```

Contracts:

- 2-char custom alphabet only;
- trim trailing whitespace and strip CR for hashing;
- symbol-only lines use line number seed;
- alphanumeric lines do not use line number seed;
- malformed refs produce `[E_BAD_REF]`;
- stale anchors produce `[E_STALE_ANCHOR]` with `>>>` retry lines;
- copied `LINE#HH:` payload fails with `[E_INVALID_PATCH]`;
- bare `HH:` payload warns/preserves content;
- all ops apply correctly;
- conflicts fail before write;
- noop returns classification and does not mutate.

### 14.2 Tool tests

Update existing:

```text
tests/CodingAgent/Tool/ReadFileToolTest.php
tests/CodingAgent/Tool/EditFileToolTest.php
```

Contracts:

- `ReadFileTool::definition()` exposes hashline prompt/schema;
- text read returns hashline rows;
- empty/OOB/offset/limit behavior;
- binary/image/special path rejection unchanged;
- `EditFileTool::definition()` exposes new `edits` schema and no `patch` field;
- read -> edit chain changes file;
- stale anchor fails without writing;
- success returns fresh anchors;
- `replace_text` exact unique replacement;
- cancellation before mutation remains safe.

### 14.3 Controller replay proof

Extend an existing deterministic controller/tool-call replay fixture for LLM-visible tool flow where practical. Add a new minimal fixture only if no existing fixture is a clear fit.

Contract:

- runtime accepts new edit schema through Symfony AI/toolbox path;
- replay event log records tool call/result correctly;
- final file state matches expected content;
- no live LLM required.

### 14.4 Validation commands

Focused during implementation:

```bash
castor test --filter Hashline
castor test --filter ReadFileToolTest
castor test --filter EditFileToolTest
castor deptrac
castor phpstan
castor cs-check
```

Full deterministic gate:

```bash
castor check
```

Because this changes tool schemas and LLM-visible prompts, also run focused live provider validation when available:

```bash
castor test:llm-real
```

If live provider prerequisites are unavailable, record the blocker explicitly; do not pretend deterministic replay proves provider compatibility.

## 15. Acceptance criteria

- [ ] `read` text output uses `LINE#HH:content` for text files.
- [ ] `edit` schema uses `path` + `edits`; `patch` is gone.
- [ ] Hashes match `pi-hashline-edit` vectors exactly.
- [ ] Anchor validation is strict and never relocates stale anchors.
- [ ] Stale failures include current `>>> LINE#HH:content` retry lines.
- [ ] `lines` payload rejects copied hashline prefixes and diff rows.
- [ ] Multi-edit application validates against one pre-edit snapshot and applies back-to-front.
- [ ] Overlapping/touching edit conflicts fail before write.
- [ ] Success returns fresh changed-region anchors.
- [ ] Noop does not rewrite the file.
- [ ] Original line endings and BOM are preserved on write.
- [ ] Existing path/binary/image/cancellation guardrails remain intact.
- [ ] Link-preserving writer preserves symlink targets, hard-link relationships, permissions/ownership where possible, and reports partial-write risks clearly in code/tests.
- [ ] Edit result includes pi-style host-only details: diff, warnings, classification, metrics, and snapshot/fingerprint where available.
- [ ] Edit pipeline uses Symfony Lock around validation and write.
- [ ] Overlong single lines are rejected with a clear error, never displayed with partial/bogus anchors.
- [ ] Unicode normalization/comparison paths use Symfony String where appropriate and are covered by tests.
- [ ] Unit/tool/controller replay tests cover the stable contracts above.
- [ ] `castor check` passes.
- [ ] `castor test:llm-real` passes or an explicit prerequisite blocker is recorded.

## 16. Resolved planning decisions

1. Seeded xxHash in PHP: require native seeded xxHash32 support; no fallback algorithm. Missing support is a user-visible tool/TUI error and future bundled PHP must include it.
2. Invalid UTF-8 behavior: keep Hatfield's current rejection behavior.
3. Filesystem identity: use link-preserving in-place writes for existing files instead of rename replacement, preserving symlinks/hard links/permissions/ownership where possible. This intentionally trades away rename-style atomic replacement for existing files; new files still use exclusive create.
4. Concurrency: use Symfony Lock around the full edit critical section despite sequential tool execution, protecting against Hatfield-controlled races and documenting external-process limits.
5. Host-only details: return pi-style structured details in addition to model-visible text.
6. Controller replay fixture shape: prefer extending an existing deterministic tool-call replay fixture; create a new fixture only if needed.
7. Long lines: reject individual lines that exceed a safe preview/hash limit instead of truncating and producing invalid anchors.
8. Unicode: prefer Symfony String for Unicode-aware normalization/comparison paths while keeping offset/slicing strategy consistent and tested.

## 17. Recommended first implementation task

Start with `LineHashComputer` and vector tests.

1. Generate vectors from `/home/ineersa/claw/pi-hashline-edit/src/hashline.ts` for representative lines:
   - normal PHP code;
   - trailing spaces;
   - CRLF content;
   - blank lines;
   - `}` and punctuation-only lines at different line numbers;
   - Unicode letters/spaces/quotes.
2. Port seeded xxHash32 faithfully.
3. Add parser/formatter next.
4. Only then modify `ReadFileTool` and `EditFileTool`.

The read/edit tool work depends on exact hash parity; do not start schema replacement until hash vectors are green.
