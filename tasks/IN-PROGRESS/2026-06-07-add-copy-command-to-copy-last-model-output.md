# Add /copy command to copy last model output

## Goal
## Goal

Add a `/copy` slash command that copies the last assistant message text to the system clipboard.

## Reference Implementation

`~/projects/cli/cli-tools/src/Tui/Utility/Clipboard.php` has a proven implementation handling macOS (`pbcopy`), Windows (`clip`), Wayland (`wl-copy`), X11 (`xclip`/`xsel`), tmux (internal buffer + OSC-52 passthrough), and plain terminal (OSC-52). Copy this into `src/Tui/Utility/Clipboard.php` with namespace `Ineersa\Tui\Utility`.

## Architecture

The `/copy` command needs `TuiSessionState` to access the transcript, so it **cannot** be a simple built-in in `SlashCommandRegistry.__construct()` (those are stateless DTO-commands like `ClearScreenCommand`). Instead, follow the **`ModelControlListener` pattern**:

### Files to create

1. **`src/Tui/Utility/Clipboard.php`**
   - Namespace: `Ineersa\Tui\Utility`
   - Copy from reference implementation (same `copy(string): bool` API, same fallback chain)
   - Uses `Symfony\Component\Process\Process` — already available (used by CodingAgent)

2. **`src/Tui/Listener/CopyCommandHandler.php`**
   - Namespace: `Ineersa\Tui\Listener`
   - Implements `SlashCommandHandler`
   - Constructor receives `TuiSessionState $state`
   - `handle(SlashCommand)`: finds the last `TranscriptBlock` with kind `TranscriptBlockKindEnum::AssistantMessage` from `$state->transcript`, extracts its `$text`, calls `Clipboard::copy()`, returns `TranscriptMessage` with success/failure
   - If no assistant message exists yet, returns a muted "Nothing to copy — no model output yet." message
   - Uses `array_filter` + `array_values` on the transcript to find the last assistant message (scan from end is fine)

3. **`src/Tui/Listener/CopyCommandRegistrar.php`**
   - Namespace: `Ineersa\Tui\Listener`
   - Implements `TuiListenerRegistrar`
   - Constructor receives `SlashCommandRegistry $commandRegistry` and `Clipboard $clipboard` (or just the class ref — it's static, no DI needed)
   - `register(TuiRuntimeContext $context)`: creates `CopyCommandHandler` with `$context->state`, registers via `$commandRegistry->register()` with metadata:
     - name: `copy`
     - aliases: `['cp']`
     - description: `Copy the last model output to the clipboard`
     - usage: `/copy`

### Deptrac changes (`depfile.yaml`)

Add a new layer for the utility:
```yaml
    - name: TuiUtility
      collectors:
        - type: directory
          value: src/Tui/Utility/.*
```

Add `TuiUtility` to the `TuiListener` ruleset (it's the only consumer):
```yaml
    TuiListener:
      - AppRuntimeContract
      - AppRuntimeProjection
      - AppSession
      - AppConfig
      - TuiRuntime
      - TuiScreen
      - TuiTranscript
      - TuiCommand
      - TuiPicker
      - TuiFooter
      - TuiQuestion
      - TuiTheme
      - TuiUtility          # <-- add this
      - SymfonyTui
```

Add `TuiUtility` ruleset (Process is needed for `pbcopy`/`xclip` etc.):
```yaml
    TuiUtility:
      - SymfonyProcess
```

### Tests

4. **`tests/Tui/Listener/CopyCommandHandlerTest.php`**
   - Test: copies last assistant message text when transcript has assistant blocks
   - Test: returns "nothing to copy" when transcript is empty or has no assistant messages
   - Test: handles transcript with mixed block types (user, tool, assistant) — picks the last `AssistantMessage`
   - Mock `Clipboard` via a trait or wrapper — since `Clipboard::copy()` spawns processes, the handler should not call it directly. Instead inject a callable or small interface:
     ```php
     // In CopyCommandHandler:
     private \Closure $copyFn;
     // Constructor: $copyFn = static fn(string $text) => Clipboard::copy($text)
     ```
   - This makes the handler testable without touching the clipboard

5. **`tests/Tui/Utility/ClipboardTest.php`**
   - Basic structural test verifying the class exists and has the `copy` method
   - The clipboard is a thin process wrapper — extensive testing of `pbcopy`/`xclip` is not feasible in CI

## Dependencies

The handler needs `TranscriptBlockKindEnum` from `CodingAgent/Runtime/Projection` — this is already allowed for `TuiListener` in deptrac.

## Acceptance criteria

- `/copy` appears in `/help` output with description
- `/copy` copies last assistant message text to system clipboard
- `/copy` shows a muted message when no model output exists
- `/cp` alias works
- `castor check` passes (PHPStan, CS, Deptrac, tests)
- No new deptrac violations

## Acceptance criteria
- /copy command appears in /help output
- /copy copies last assistant message text to clipboard
- /copy shows muted message when no model output
- /cp alias works
- castor check passes (PHPStan, CS, Deptrac, tests)
- No deptrac violations

## Workflow metadata
Status: IN-PROGRESS
Branch: task/2026-06-07-add-copy-command-to-copy-last-model-output
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-06-07-add-copy-command-to-copy-last-model-output
Fork run:
PR URL:
PR Status:
Started: 2026-06-08T15:50:52.930Z
Completed:

## Work log
- Created: 2026-06-07T23:55:11.356Z

## Task workflow update - 2026-06-08T15:50:52.930Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-06-07-add-copy-command-to-copy-last-model-output.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-06-07-add-copy-command-to-copy-last-model-output.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/2026-06-07-add-copy-command-to-copy-last-model-output.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/2026-06-07-add-copy-command-to-copy-last-model-output.
