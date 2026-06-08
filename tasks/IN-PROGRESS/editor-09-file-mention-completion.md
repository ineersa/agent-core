# EDITOR-09 File mention completion and resolution

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Add FileMentionCompletionProvider for @ tokens at token boundaries.
- Search CWD using fd when available, respecting .gitignore; fallback to PHP filesystem traversal.
- Insert quoted paths when necessary, e.g. @"path with spaces/file.php".
- Keep file mention completion in editor/completion layer; runtime context expansion can remain a later integration point if not already available.

Exclusions:
- No LSP/fuzzy semantic indexing.
- No image/file attachment paste.
- No runtime prompt context injection beyond path insertion unless existing APIs support it.

Dependencies: EDITOR-08.
Parallelizable with: EDITOR-10.

## Acceptance criteria
- @ token boundary detection is covered by tests.
- Provider returns reasonable path suggestions from the project CWD.
- fd and PHP fallback paths are both testable or abstracted behind a service for deterministic tests.
- Accepted suggestions insert correctly quoted paths when needed.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/editor-09-file-mention-completion
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-09-file-mention-completion
Fork run: km3v1irhhahm
PR URL:
PR Status:
Started: 2026-06-08T23:47:05.979Z
Completed:

## Work log
- Created: 2026-05-18T00:16:03.500Z

## Task workflow update - 2026-06-08T23:45:03.628Z
- Summary: Implementation brief updated after planning discussion. This supersedes the original backend note that mentioned fd/PHP recursive fallback: EDITOR-09 should not depend on fd, should not auto-download tools, should not use git ls-files, and should not do recursive filesystem traversal in the TUI input hot path. The agreed direction is an indexed, non-fuzzy file/path mention completion system: a background/scheduled index builder scans the project with Symfony Finder and atomically writes a newline/JSONL cache file; the TUI provider loads that cache into memory and performs cheap string/path matching while the user types @ mentions.
- ## Implementation brief — indexed @ file mention completion (supersedes original fd/PHP fallback wording)

### Product decision
- Implement @ file mention completion without fd, without auto-downloading binaries, without git ls-files, and without recursive PHP filesystem scans during input handling.
- We accept losing pi-style fuzzy fd search for now. The MVP should provide fast, non-fuzzy project path search backed by an index.
- Later work can improve the in-memory data structure or add richer fuzzy search, but EDITOR-09 should stay simple and safe.

### Reference findings from pi-mono scout
- pi-mono uses fd as the only backend for @ file mention completion.
- pi-mono auto-downloads fd into ~/.pi/agent/bin/fd if it is missing, then executes it.
- pi-mono has no find/git/PHP fallback for @ fuzzy search; if fd is unavailable, @ suggestions return nothing.
- We intentionally do NOT copy this backend because downloading and executing third-party binaries as part of completion is not acceptable for Hatfield/agent-core.
- Good UX details to copy from pi-mono: @ token boundary detection, quoted insertions, directories with trailing slash, files with trailing space, Up/Down menu navigation, Tab accept, Escape close, and directory/file ranking.

### High-level architecture
Add a small file mention indexing subsystem plus a completion provider:

1. Background/scheduled index builder
   - Uses Symfony Finder to scan the project CWD outside the TUI input hot path.
   - Refreshes periodically, e.g. every 30 seconds, or on first startup/first @ usage if index is missing/stale.
   - Writes to a temp file and atomically renames it into place so TUI readers never see partial data.
   - Uses a lock/flock so only one scan per CWD runs at a time.
   - On scan failure, leaves the previous index untouched.

2. Index cache file
   - Store under a runtime/cache path, e.g. .hatfield/cache/file-mentions/<cwd-hash>.jsonl or .hatfield/tmp/file-mentions/<cwd-hash>.jsonl.
   - One entry per file/directory.
   - Prefer JSONL for typed fields, e.g. {"path":"src/Tui/Completion/CompletionListener.php","dir":false} and {"path":"src/Tui/Completion/","dir":true}.
   - If using plain newline text, encode dirs consistently with a trailing slash.
   - Write tmp file in same directory, fsync if practical, then rename(tmp, index) for atomic replacement.

3. In-memory reader/index in TUI
   - TUI provider watches index mtime and reloads if changed.
   - Completion filtering uses memory only; no filesystem traversal or subprocess in the input handler.
   - Maintain at least:
     - flat entries list for global non-fuzzy search
     - optional childrenByDirectory map for path-style directory lookup
     - lowercase normalized path/basename fields for cheap case-insensitive matching

4. Completion provider
   - Add FileMentionCompletionProvider under src/Tui/Completion/ (or subnamespace if cleaner).
   - It consumes CompletionContext and the in-memory file mention index reader.
   - It detects active @ token at a token boundary and returns CompletionSuggestion instances with replacement range covering the active token.
   - It must compose with existing slash command completion from EDITOR-08 via a provider registry/composite; do not regress slash completion.

### Symfony Finder usage for index builder
- Symfony Finder is acceptable for the background/scheduled scan, not the live input path.
- Suggested scan shape:
  - Finder::create()
  - ->in($cwd)
  - include both files and directories (if Finder mode cannot include both with one call, use two finders or no files()/directories() mode and filter SplFileInfo)
  - ->ignoreVCS(true)
  - ->ignoreUnreadableDirs(true)
  - ->ignoreDotFiles(false) if hidden files should be searchable; otherwise keep dotfiles hidden unless product decides shell-style hidden behavior.
  - ->ignoreVCSIgnored(true) may be enabled as best-effort when scanning from project CWD root, but do not rely on it as the only ignore model because Git worktrees can have a .git file instead of .git directory.
  - Explicitly exclude noisy/runtime directories: .git, vendor, node_modules, var, .hatfield/sessions, .hatfield/tmp, .hatfield/cache as appropriate.
- Add a hard cap, e.g. max 50k entries, to prevent pathological repositories from creating huge index files or blocking too long.
- Sort output deterministically before writing, or use deterministic insertion and provider scoring.

### Scheduler / worker shape
- Do not attempt request/response through Messenger consumers for completion; completion needs immediate local results.
- If Symfony Scheduler is added/configured later, use it to dispatch a recurrent index-refresh task. Current composer setup does not have symfony/scheduler installed as a direct dependency, so implementation may need either:
  - a lightweight process/tick-triggered background command that starts from the TUI controller lifecycle, or
  - adding/configuring Symfony Scheduler explicitly if the project accepts that dependency.
- Whichever mechanism is chosen, the scanner must run outside the TUI input handler and publish via the atomic cache file.
- If no index exists yet, provider should return no suggestions or a lightweight "indexing files..." item only if that fits the current menu model. Do not block waiting for the first full scan.

### Matching semantics
Support non-fuzzy matching over indexed paths:
- @foo should search all indexed paths for foo (case-insensitive substring/prefix), not only the current directory.
- @src/Tui should match indexed paths beginning with src/Tui and/or children under src/ depending on parser result.
- Prefer ranking in this order:
  1. directory/path prefix matches
  2. basename prefix matches
  3. full path prefix matches
  4. basename contains
  5. full path contains
  6. directories get a small bonus so folders appear before files when equally relevant
- Cap suggestions, e.g. top 20/50, to keep the menu usable.
- No fuzzy typo tolerance in EDITOR-09.

### @ token parsing
- Trigger only when @ is at a token boundary, e.g. start of text or after whitespace/delimiters.
- Do not trigger for email@example.com or foo@bar.
- Support quoted paths: @"path with spaces" and partial quoted input @"path with.
- Detection should use CompletionContext text/cursorByteOffset rather than assuming cursor-at-end where practical, but current EDITOR-08 listener still calls CompletionContext::forCursorAtEnd; do not create production cursor hacks.
- If cursor-aware replacement is too large for this task, document current cursor-at-end MVP behavior with tests.

### Insertion/quoting rules
- Suggested values should replace the active @ token/range.
- Files insert a trailing space so the user can continue the prompt: @src/Foo.php<space>.
- Directories insert trailing / and do NOT append a trailing space so the user can continue navigating.
- Paths containing spaces or quote-required characters should insert quoted mentions: @"path with spaces/file.php".
- If the user already typed @", preserve quoted context and avoid double quotes.
- For quoted directory suggestions, place/represent insertion so continued typing remains inside the quote if the current editor API can support it without cursor hacks. If not practical in current API, prefer a simple safe behavior and document it in tests.
- Escape embedded quotes/backslashes defensively if supporting them.

### UI/input behavior to preserve from EDITOR-08
- Live open/refine: typing @ should open file mention suggestions once the index is available; typing more should refine suggestions.
- Tab accepts selected suggestion.
- Escape closes the menu without clearing editor text.
- Up/Down navigates while menu is open.
- Shift+Tab behavior from EDITOR-08 should remain unchanged.
- Slash command completion must continue working exactly as after EDITOR-08.
- Completion rendering should continue using the existing CompletionMenu/SelectListWidget style below the editor and theme accent highlighting.

### Files likely affected
- src/Tui/Completion/CompletionProvider.php — may need provider composition or context/range conventions if not already sufficient.
- src/Tui/Completion/CompletionSuggestion.php — ensure it can express replacement range/value for @ tokens.
- src/Tui/Completion/CompletionState.php — verify provider-agnostic state still works.
- src/Tui/Completion/SlashCommandCompletionProvider.php — should remain slash-specific; do not mix file logic into it.
- src/Tui/Completion/FileMentionCompletionProvider.php — new provider.
- src/Tui/Completion/FileMentionIndexEntryDTO.php or similar semantic suffix — new typed entry.
- src/Tui/Completion/FileMentionIndexReader.php / Repository / Provider — new reader/cache service.
- src/Tui/Completion/FileMentionIndexBuilder.php — new Finder-based scanner/writer service, likely not used in hot path.
- src/Tui/Listener/CompletionListener.php — generalize constructor from concrete SlashCommandCompletionProvider to provider registry/composite if not already done; preserve priority behavior.
- config/services.yaml — wire/tag completion providers and index services.
- depfile.yaml — update TuiCompletion dependencies if the index builder/reader uses AppConfig, Symfony Finder, Symfony Process, or filesystem helpers. Keep TuiEditor free of app/runtime deps.

### Boundary/deptrac notes
- Keep editor widget/text mutation in src/Tui/Editor minimal and generic; file mention logic belongs in TuiCompletion/TuiListener.
- TuiCompletion may need allowed dependencies on AppConfig and Symfony Finder if the index builder lives there. Alternative: put scanner/cache writer in CodingAgent/App layer and keep TuiCompletion only reading a narrow interface/DTO.
- TuiListener already depends on TuiCompletion and TuiEditor after EDITOR-08; preserve existing layer boundaries.
- Run castor deptrac after any layer changes.

### Tests to add/update
- tests/Tui/Completion/FileMentionCompletionProviderTest.php
  - @ boundary at start of text.
  - @ boundary after whitespace/delimiter.
  - no trigger for email@example.com / foo@bar.
  - quoted @"... parsing.
  - suggestions from in-memory index for global non-fuzzy matches.
  - directory suggestions include trailing / and no trailing space.
  - file suggestions include trailing space on accept path/value.
  - paths with spaces are quoted.
  - ranking: dirs/prefix/basename before generic contains.
- tests/Tui/Completion/FileMentionIndexReaderTest.php
  - reloads when mtime changes.
  - ignores partial temp files / handles missing index.
  - parses JSONL/newline format deterministically.
- tests/Tui/Completion/FileMentionIndexBuilderTest.php
  - use temp directories.
  - writes tmp then atomically renames.
  - excludes .git/vendor/node_modules/var/.hatfield runtime dirs.
  - includes files and directories.
  - caps entries.
  - keeps old index on failure if feasible to test without production-only hooks.
- tests/Tui/Listener/CompletionListenerTest.php
  - @ live opens/refines file suggestions.
  - Tab accepts file suggestion.
  - Escape closes without clearing.
  - Up/Down navigation works for file suggestions.
  - slash command completion still works.
  - no regression for PromptHistory Up/Down on empty editor when completion menu is closed.

### Validation commands
Use Castor only:
- castor test --filter=FileMention
- castor test --filter=Completion
- castor test --filter=PromptHistory
- castor deptrac
- castor phpstan
- castor cs-check
- Because this touches TUI input/completion behavior, before CODE-REVIEW the full gate must pass via LLM_MODE=true castor check (move_task to CODE-REVIEW runs this automatically).

### Risks / decisions for implementor to surface if ambiguous
- Exact scheduler/worker mechanism is not finalized. Do not block TUI input on indexing; if a full scheduler dependency is too large, implement a minimal safe background refresh trigger and document tradeoffs.
- Symfony Finder ignoreVCSIgnored(true) may not fully apply root .gitignore in Git worktrees. Use explicit excludes and treat gitignore as best-effort.
- Very large repos need caps/timeouts/old-index retention.
- Cursor placement inside quoted directory completions may be limited by current Symfony EditorWidget public API. Do not use ReflectionClass, Closure::bind, or private-property hacks in production.
- Do not add production APIs solely for tests. Use real constructors/services or test-local fixtures/builders.

## Task workflow update - 2026-06-08T23:47:05.980Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-09-file-mention-completion.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-09-file-mention-completion.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-09-file-mention-completion.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-09-file-mention-completion.
- Summary: Started implementation phase for EDITOR-09. Scope is indexed, non-fuzzy @ file/path mention completion using a background/safe Symfony Finder index builder and in-memory provider search; no fd, no auto-download, no git ls-files, no recursive filesystem work in TUI input handling.

## Task workflow update - 2026-06-08T23:50:07.482Z
- Recorded fork run: km3v1irhhahm
- Summary: Launched implementation fork km3v1irhhahm in worktree /home/ineersa/projects/agent-core-worktrees/editor-09-file-mention-completion. Fork scope: implement indexed non-fuzzy @ file mention completion; add provider composition; add Finder-based background index builder + atomic cache; add CLI command and TUI tick-triggered async refresh process if feasible; add in-memory reader/provider; preserve EDITOR-08 completion behavior; add comprehensive tests; run focused Castor validation; commit and leave worktree clean.
