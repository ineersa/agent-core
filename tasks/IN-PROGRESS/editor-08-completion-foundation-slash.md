# EDITOR-08 Completion foundation and slash command completion

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Add CompletionProvider, CompletionSuggestion, and CompletionState.
- Add completion menu rendering in/near the editor.
- Add SlashCommandCompletionProvider backed by the slash command registry metadata.
- Implement Tab behavior: accept selected item when menu is open; trigger slash completion when slash context is detected.
- Escape closes completion without clearing editor text.

Exclusions:
- No file mention completion; EDITOR-09 owns @ provider.
- No configurable keybindings.
- No command execution changes beyond existing registry metadata.

Dependencies: EDITOR-03, EDITOR-04.
Parallelizable with: EDITOR-06, EDITOR-07.

## Acceptance criteria
- Completion provider interface and state are unit-tested.
- Slash command suggestions appear for slash context at start of editor text or after newline at column 0.
- Tab accepts selected slash command suggestion.
- Escape closes completion state.
- Completion rendering stays separate from command execution.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/editor-08-completion-foundation-slash
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash
Fork run: vj4a4dwv1v0w
PR URL:
PR Status:
Started: 2026-06-08T15:02:47.293Z
Completed:

## Work log
- Created: 2026-05-18T00:15:55.603Z

## Task workflow update - 2026-06-08T15:01:09.969Z
- Summary: Task-explain planning update for autonomous implementation. User decisions: start with current PromptEditor::setText()/text replacement behavior even though Symfony resets cursor to line 0/col 0; user will smoke test and decide whether cursor-after-insert needs follow-up. Alias matching is required: typing an alias prefix such as /q should suggest canonical /exit, and acceptance should insert the canonical command. Completion menu should support Up/Down navigation, reusing patterns from question/model selection. Input routing should be attempted via TUI-level InputEvent listener rather than replacing PromptHistoryListener's EditorWidget::onInput() callback, preserving EDITOR-07 behavior.
- Autonomous implementation brief for EDITOR-08:

Goal: add the TUI completion foundation and slash-command completion only. Do not implement file mention completion (EDITOR-09), configurable keybindings, or command execution changes. Completion must render near the editor, use existing slash command metadata, accept selected suggestions with Tab, close with Escape without clearing editor text, and keep command execution separate from completion.

Current architecture facts:
- Current prompt input is Symfony TUI EditorWidget wrapped by src/Tui/Editor/PromptEditor.php. PromptEditor::getWidget() exposes the EditorWidget; getText()/setText()/clear()/extract() delegate to EditorWidget.
- ChatScreen exposes editorWidget(), promptEditor(), editorText(), clearEditor(), extract(), insertOverlayBeforeEditor(AbstractWidget), removeOverlay(AbstractWidget), setFocus(AbstractWidget), and requestRender(bool). insertOverlayBeforeEditor() places widgets directly above the editor area.
- EDITOR-07 added src/Tui/Listener/PromptHistoryListener.php. It installs an EditorWidget::onInput() callback for Up/Down history. Symfony EditorWidget::onInput() is single-slot: calling onInput() again replaces the history callback. Do NOT overwrite it. Preserve prompt history behavior.
- Symfony Tui::handleInput() dispatches InputEvent before focused widget input. If InputEvent propagation is stopped, EditorWidget never receives the key. This is the recommended seam for EDITOR-08.
- Existing TUI-level input listener patterns: CtrlCInputInterceptor priority 100 for Ctrl+C/Ctrl+D; ModelControlListener priority 95 for Ctrl+P and Shift+Tab (\x1b[Z); InteractiveMode slot input handlers priority 50. Completion should not steal Shift+Tab. Suggested priority: around 90, below CtrlC and ModelControl but above slot handlers/focused editor input.
- Tab is Symfony Key::TAB / raw \t in normal terminals. EditorWidget has no default Tab binding, so without interception Tab can fall through as control/no-op/undesired input. Completion must stop propagation when it handles Tab.
- Escape is EditorWidget select_cancel and currently leads to CancelEvent handled by CancelListener (clears editor when idle or cancels active run). When completion menu is open, Escape must close completion and stop propagation so editor text is not cleared and active run is not cancelled.
- Slash command registry: src/Tui/Command/SlashCommandRegistry.php has allMetadata(), allMetadataMap(), getMetadata(), has(), register(), setHandler(), execute(). Built-ins include /help, /clear, /exit; runtime listeners such as ModelControlListener register /model during TuiListenerRegistrar::register(). Provider must read registry metadata at suggestion time so runtime-registered commands are included.
- Command metadata DTO: src/Tui/Command/CommandMetadata.php has name, aliases, description, usage. Use it for display/descriptions. Do not change command execution routing.
- Command parsing/execution flow after Enter is already handled by SubmitListener -> SubmissionRouter -> CommandParser -> SlashCommandRegistry. Completion must not execute commands.
- Deptrac: TuiEditor layer currently allows only TuiWidget, TuiTheme, SymfonyTui; it must not depend on TuiCommand. Because slash completion depends on SlashCommandRegistry, do NOT put command-aware completion classes under src/Tui/Editor/Completion unless deptrac is intentionally changed. Recommended: create src/Tui/Completion/ and add a TuiCompletion layer in depfile.yaml, allowing TuiCompletion -> TuiCommand (and maybe TuiTheme/SymfonyTui only if UI data types require it; prefer pure provider/model types). Add TuiListener -> TuiCompletion.

Recommended files/classes to add:
1. src/Tui/Completion/CompletionProvider.php
   - Interface for pure suggestion providers.
   - Suggested signature should include enough context for future providers: current editor text and cursor/selection context if available. Since current PromptEditor exposes only getText(), an MVP signature like getSuggestions(string $text): list<CompletionSuggestion> is acceptable, but prefer a typed context DTO if it stays small.
   - Returns empty list when provider does not apply.

2. src/Tui/Completion/CompletionSuggestion.php
   - readonly DTO.
   - Suggested fields: label/display (e.g. '/help'), insertText (e.g. '/help '), description, matchedPrefix, replacementStart/replacementLength or enough replacement metadata to replace current token. Keep it explicit so later file/session providers can replace only the active token.
   - For alias matches, display can mention canonical plus alias, but insertText must be canonical command text (e.g. typing /q suggests /exit and inserts '/exit ').

3. src/Tui/Completion/CompletionState.php
   - Pure state machine, unit-tested.
   - Tracks visible/open flag, list<CompletionSuggestion>, selectedIndex, active replacement range/prefix if not stored on each suggestion.
   - Methods should cover open(suggestions), close(), isOpen(), selected(), moveNext()/movePrevious() with wrapping, acceptSelected(). Up/Down navigation required by user.
   - Escape closes state without mutating editor text.

4. src/Tui/Completion/SlashCommandCompletionProvider.php
   - Inject SlashCommandRegistry.
   - Trigger only for slash context at start of editor text or after newline at column 0. Since cursor position is not currently exposed, use current full text as cursor-at-end MVP. This means contexts like '/he' and "hello\n/cl" should trigger; 'hello /he' should not; '  /he' should not; '//' should not be treated as command completion unless explicitly decided later.
   - Match canonical command names and aliases. Required user decision: alias prefixes must suggest canonical command, e.g. /q suggests /exit. Acceptance inserts canonical command with trailing space when appropriate.
   - Suggestions should use SlashCommandRegistry::allMetadata() at call time, not constructor time, so /model registered during TUI listener registration appears.
   - Sort deterministically. Registry allMetadata() is sorted by canonical name; preserve predictable order. If alias and canonical both match same command, return one suggestion.

5. src/Tui/Listener/CompletionListener.php
   - Implements TuiListenerRegistrar and is auto-tagged by services.yaml _instanceof.
   - Inject provider(s) and completion state/overlay collaborator as services. If multiple providers are not implemented yet, inject SlashCommandCompletionProvider directly or iterable<CompletionProvider> if DI tagging is added; keep it simple.
   - Register a TUI InputEvent listener, not EditorWidget::onInput(). Suggested priority: ~90 (below CtrlCInputInterceptor priority 100 and ModelControlListener priority 95, above slot handlers priority 50 and focused EditorWidget input).
   - On Tab when state/menu closed: get current editor text from context->screen->editorText() or promptEditor()->getText(), query slash provider, open menu if suggestions non-empty, stopPropagation, requestRender. If no suggestions, likely stopPropagation to make Tab a completion/no-op for slash context; for non-context it may pass through/no-op. Decide explicitly in tests.
   - On Tab when state/menu open: accept selected suggestion, replace active slash prefix in editor, close menu, stopPropagation, requestRender.
   - On Escape when menu open: close menu, stopPropagation, requestRender. This prevents CancelListener/editor cancel from clearing text.
   - On Up/Down when menu open: update selected index with wrap, stopPropagation, requestRender. User explicitly requested Up/Down navigation, using model/question picker examples as precedent.
   - On normal printable input while menu open: recommended MVP is close completion and let input pass through so the editor updates normally; user can press Tab again to refresh. Alternative live filtering is optional, not required by acceptance.
   - Must preserve PromptHistoryListener behavior: Up/Down history works when completion closed; completion Up/Down only intercept when completion menu open.

6. Completion rendering/overlay
   - Use ChatScreen::insertOverlayBeforeEditor() to render near editor. Prefer a small ContainerWidget/TextWidget or SelectListWidget-based menu. Existing patterns: PickerOverlay and ChatScreen::insertOverlayBeforeEditor(); QuestionController also inserts overlays before editor.
   - Keep editor focus if possible so normal typing continues. If SelectListWidget requires focus for navigation, it may conflict with Tab accept/typing; because CompletionListener handles Up/Down/Tab/Escape at InputEvent level, a non-focused rendered list may be simpler. A focused SelectListWidget is acceptable only if tests prove editor focus/typing behavior remains correct.
   - Render label and description from CompletionSuggestion/CommandMetadata. Keep rendering separate from SlashCommandRegistry::execute().
   - Ensure overlay lifecycle is idempotent: open replaces/updates existing widget, close removes it once, no widget leaks.

7. Completion acceptance/text replacement
   - Current PromptEditor::setText() resets Symfony EditorDocument cursor to line 0/col 0. User decision: start with this behavior; user will smoke test and decide if cursor-after-insert requires follow-up. Do not over-engineer private EditorDocument access or reflection.
   - Text replacement MVP can replace the current slash token at end of text, e.g. '/he' -> '/help ', "hello\n/cl" -> "hello\n/clear ". Preserve preceding lines. Use explicit replacement range from provider/state if implemented.
   - Do not add production APIs solely for tests. If a small production method on PromptEditor/ChatScreen is useful for actual completion insertion (e.g. replaceText(string) or setEditorText(string)), it is acceptable; avoid ReflectionClass/Closure hacks.

Modified files likely required:
- depfile.yaml: add TuiCompletion collector src/Tui/Completion/.* and rules. Add TuiListener -> TuiCompletion. If completion rendering model depends on SymfonyTui/TuiTheme, allow only what is necessary.
- config/services.yaml probably needs no change if classes are autowired under Ineersa\Tui\ resource. Only add provider tagging if implementing iterable providers; not required for MVP.
- src/Tui/Editor/PromptEditor.php and/or src/Tui/Screen/ChatScreen.php only if needed for production text replacement convenience. Keep changes minimal.
- Do not modify SlashCommandRegistry unless a genuinely missing metadata API is found; current allMetadata() should be enough.

Tests to add/update:
- tests/Tui/Completion/CompletionStateTest.php: open/close, selected item, Tab accept behavior via acceptSelected(), Up/Down wrapping, empty suggestions behavior, Escape close semantics.
- tests/Tui/Completion/SlashCommandCompletionProviderTest.php: suggests built-ins from registry; filters by prefix; triggers at text start; triggers after newline column 0; does not trigger mid-line or leading spaces; matches aliases (/q -> canonical /exit); includes runtime-registered commands by registering a test command in SlashCommandRegistry before querying; deterministic ordering; no duplicate canonical suggestions when alias and name both match.
- tests/Tui/Listener/CompletionListenerTest.php: Tab opens slash completion menu and stops propagation; Tab accepts selected suggestion and updates editor text; Escape closes menu without clearing editor text; Up/Down navigate selected suggestion when menu open; Up/Down history remains unaffected when menu closed; Tab does not steal Shift+Tab (\x1b[Z) model reasoning shortcut; completion overlay opens/closes without leaking; command execution is not invoked on Tab.
- Existing helpful patterns: tests/Tui/Listener/PromptHistoryListenerTest.php constructs PromptEditor, TuiSessionState, ChatScreen, TuiRuntimeContext and calls handleInput(); tests/Tui/Picker/PickerOverlayTest.php covers overlay lifecycle; tests/Tui/Command/SlashCommandRegistryTest.php uses test handlers and metadata registration.

Validation after implementation (Castor only):
- castor test --filter=Completion
- castor test --filter=PromptHistory
- castor deptrac
- castor phpstan
- castor cs-check
- Full required before CODE-REVIEW because this touches TUI runtime behavior: LLM_MODE=true castor check

Explicit user decisions captured 2026-06-08:
- Cursor placement after accepting completion: start with current setText/text replacement behavior despite cursor reset; user will test and decide on follow-up.
- Alias behavior: yes, aliases should match and insert canonical command. Example: /q suggests /exit and acceptance inserts /exit.
- Menu navigation: yes, implement Up/Down navigation for the completion menu, following question/model selection UX patterns.
- Input routing: yes, try TUI-level InputEvent for completion instead of composing into EditorWidget::onInput(), preserving EDITOR-07 PromptHistoryListener unchanged.

Risks/notes for implementor:
- Single-slot onInput remains occupied by PromptHistoryListener. Do not call editorWidget()->onInput() in completion unless intentionally replacing with a composite dispatcher; current plan avoids this.
- Escape behavior is critical: if completion menu is open, stop propagation or CancelListener/editor cancel may clear editor/cancel active run.
- Deptrac is likely to fail if command-aware completion is placed under src/Tui/Editor. Use src/Tui/Completion with explicit layer rules.
- Runtime-registered commands like /model must be available. Ensure CompletionListener registration order does not query provider before ModelControlListener registers /model; querying on Tab rather than constructor/open-time service creation should handle this.
- Completion rendering must not execute commands. Enter remains submit; Tab accepts completion.
- No file mention provider in this task; leave provider shape ready for EDITOR-09.

## Task workflow update - 2026-06-08T15:02:47.293Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-08-completion-foundation-slash.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash.

## Task workflow update - 2026-06-08T15:07:22.409Z
- Summary: Task-start scout context gathered. Worktree: /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash. Two scout subagents inspected TUI InputEvent/overlay/listener APIs and slash registry/deptrac/service/test setup. Implementation fork should use TUI-level InputEvent listener at priority ~90; preserve PromptHistoryListener's single-slot EditorWidget::onInput(); create src/Tui/Completion layer and depfile rules; use SlashCommandRegistry::allMetadata() at suggestion time; add completion state/provider/listener tests; run focused Castor validation only in task-start.
- Scout findings for fork instructions:
- TuiRuntimeContext constructor properties: public Tui $tui, AgentSessionClient $client, TuiSessionState $state, ChatScreen $screen, HatfieldSessionStore $sessionStore, TuiTickDispatcher $ticks = new TuiTickDispatcher().
- TuiListenerRegistrar::register(TuiRuntimeContext $context): void is auto-tagged by services.yaml _instanceof app.tui_listener; no service config changes expected for a new CompletionListener.
- InputEvent carries raw bytes via getData() and inherits stopPropagation(). Existing priorities: CtrlCInputInterceptor 100; ModelControlListener Ctrl+P/Shift+Tab 95; slot input handlers 50. CompletionListener should use InputEvent priority around 90. Raw keys: Tab \x09, Shift+Tab \x1b[Z, Escape \x1b, Up \x1b[A / \x1bOA, Down \x1b[B / \x1bOB. Prefer editor keybindings matches for Up/Down/Escape where practical.
- PromptHistoryListener owns EditorWidget::onInput(), and KeybindingsTrait stores a single ?Closure. Do not call editorWidget()->onInput() from completion. Completion Up/Down must intercept only when completion is open; when closed, let Up/Down reach PromptHistoryListener/editor.
- ChatScreen overlay/editor API: insertOverlayBeforeEditor(AbstractWidget), removeOverlay(AbstractWidget), setFocus(AbstractWidget), requestRender(bool), editorWidget(), promptEditor(), editorText(), clearEditor(), extract(). insertOverlayBeforeEditor inserts above editor after screen mount.
- SlashCommandRegistry has register(), has(), getMetadata(), allMetadata(), allMetadataMap(), setHandler(). Built-in aliases: help ['h','?'], clear ['cls'], exit ['quit','q']. ModelControlListener registers /model with alias ['m'] at runtime. Provider must query allMetadata() at suggestion time, not cache in constructor.
- CommandMetadata readonly DTO fields: name, aliases, description, usage. Alias completion required by user: /q suggests/inserts canonical /exit.
- Deptrac current TuiEditor cannot depend on TuiCommand. Recommended new src/Tui/Completion layer with depfile TuiCompletion rules allowing TuiCommand and only necessary UI/theme deps; add TuiCompletion to TuiListener allowed deps.
- Useful test patterns: tests/Tui/Listener/PromptHistoryListenerTest.php and CancelListenerTest.php for TuiRuntimeContext + listener extraction; tests/Tui/Picker/PickerOverlayTest.php and ChatScreenTest.php for overlays; tests/Tui/Command/SlashCommandRegistryTest.php for registry metadata/aliases.

## Task workflow update - 2026-06-08T15:08:24.302Z
- Recorded fork run: 0cchvpspn680
- Summary: Launched implementation fork 0cchvpspn680 in worktree /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash. Fork instructions cover src/Tui/Completion provider/state/suggestion/provider classes, CompletionListener via InputEvent priority 90, depfile layer updates, completion overlay rendering, alias matching, Up/Down navigation, focused tests, Castor-only validation, and commit/report requirements. Orchestrator will wait for fork report and will not implement directly or proceed to PR/review.

## Task workflow update - 2026-06-08T15:33:26.752Z
- Recorded fork run: 0cchvpspn680
- Validation: git -C /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash status --short && git log -1 --oneline: clean; 33772c81 feat(editor-08): add slash command completion foundation; git -C /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash diff --stat main...HEAD: 9 files changed, 1533 insertions; castor test --filter=Completion: ok (tests=64, assertions=141, errors=0, failures=0, skipped=0); castor test --filter=PromptHistory: ok (tests=29, assertions=58, errors=0, failures=0, skipped=0); castor deptrac: ok (violations=0, errors=0, uncovered=734, allowed=921); castor phpstan: ok (errors=0, file_errors=0); castor cs-check: ok (files_fixed=0)
- Summary: Implementation fork 0cchvpspn680 completed successfully at commit 33772c81 (feat(editor-08): add slash command completion foundation). Verified worktree status clean and diff stat main...HEAD shows 9 expected files changed: depfile.yaml plus new src/Tui/Completion classes (CompletionProvider, CompletionState, CompletionSuggestion, SlashCommandCompletionProvider), new src/Tui/Listener/CompletionListener, and 3 new test files. Functionality implemented: slash command completion provider/state/suggestion foundation, InputEvent-based CompletionListener priority 90 preserving PromptHistoryListener onInput, overlay rendered above editor, Tab open/accept, Escape close without clearing text, Up/Down navigation, alias matching (/q -> /exit), runtime metadata lookup via SlashCommandRegistry::allMetadata(), deptrac TuiCompletion layer. Note: fork report listed raw vendor/bin validation, which is not acceptable per project rules; orchestrator reran focused validation through Castor and it passed.

## Task workflow update - 2026-06-08T15:47:22.246Z
- Recorded fork run: cf6u7r92u2yu
- Summary: Reviewer subagent returned APPROVE WITH SUGGESTIONS for HEAD 33772c81. No critical/bug/security issues. Actionable suggestions selected for review-iteration fork: add forward-compatible CompletionContext DTO and update provider signature; fix Ctrl+C/Ctrl+D stale completion overlay by adding high-priority close-only listener that does not stop propagation; isolate tabDoesNotExecuteSlashCommand test so it does not register two CompletionListeners on one Tui; clarify misleading /cl alias comment. Skipped only low-risk future perf refactor (overlay in-place update) and NTH acceptSelected alias removal as non-blocking/not necessary. Launched review-fix fork cf6u7r92u2yu in worktree /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash.

## Task workflow update - 2026-06-08T16:02:16.011Z
- Recorded fork run: vj4a4dwv1v0w
- Summary: Re-review after fix commit ffa23685 again returned APPROVE WITH SUGGESTIONS, with no critical/bug/security issues. All prior findings were addressed. Remaining sensible low-risk items: strengthen registry-order test to assert explicit sorted order; add documenting test for current MVP behavior when CompletionContext has non-end cursor offset; attempt clean rebase onto origin/main for branch hygiene if safe. Launched fork vj4a4dwv1v0w to address those items with Castor-only validation.

## Task workflow update - 2026-06-08T16:19:58.144Z
- Validation: Reviewer subagent on /home/ineersa/projects/agent-core-worktrees/editor-08-completion-foundation-slash at HEAD 01915f6f: APPROVE; git status --short --branch: ## task/editor-08-completion-foundation-slash (clean); castor test: ok (tests=2112, assertions=6145, errors=0, failures=0, skipped=0); castor deptrac: ok (violations=0, errors=0, uncovered=735, allowed=923); castor phpstan: ok (errors=0, file_errors=0); castor cs-check: ok (files_fixed=0)
- Summary: Final reviewer subagent verdict for current HEAD 01915f6f: APPROVE. Reviewer confirmed prior suggestions resolved: explicit registry order assertion in SlashCommandCompletionProviderTest and documenting non-end CompletionContext cursor-offset MVP behavior test. No critical issues, bugs, or blockers. Branch was rebased cleanly on origin/main by fork vj4a4dwv1v0w; current task worktree status clean.
- task-to-pr final review: reviewer approved HEAD 01915f6f after review-iteration forks cf6u7r92u2yu and vj4a4dwv1v0w. Focused Castor validation passed locally; proceeding to move_task(to="CODE-REVIEW") for full LLM_MODE=true castor check quality gate and PR creation.
