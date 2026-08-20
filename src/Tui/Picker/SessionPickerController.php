<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Widget\SelectListKeybindings;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Manages the interactive session picker overlay lifecycle.
 *
 * Opens an interactive SelectListWidget when invoked without
 * arguments.  Supports two modes:
 *   - Resume mode (open()): Enter resumes the selected session;
 *     d/D deletes after Yes/No confirm (active session blocked).
 *   - Rename mode (openForRenameCommand()): Enter inserts
 *     "/rename <id> " into the prompt editor; the user then
 *     types a new name and submits.
 *
 * Arrow keys navigate; Enter confirms; Escape cancels.
 *
 * Sessions are fetched fresh from HatfieldSessionStore on each
 * open so the picker always reflects the latest DB state.
 *
 * While open, resume mode may hold picker-scoped confirm state
 * ({@see $confirmingDelete}, {@see $pendingDeleteSessionId}) for the
 * in-overlay Yes/No delete step. {@see closePicker()} clears that
 * state together with the overlay widgets.
 */
final class SessionPickerController
{
    private const string RESUME_HEADER = 'Resume session — arrows move, Enter resumes, d deletes, Esc cancels';
    private const string CONFIRM_YES = 'yes';
    private const string CONFIRM_NO = 'no';

    private ?PickerOverlay $overlay = null;
    private ?TextWidget $headerWidget = null;

    /** @var list<array{sessionId: string, displayTitle: string, name: string, ...}> */
    private array $sessions = [];

    private bool $confirmingDelete = false;
    private ?string $pendingDeleteSessionId = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly ChatScreen $screen,
        private readonly HatfieldSessionStore $sessionStore,
        private readonly TuiSessionSwitchServiceInterface $switch,
    ) {
    }

    /**
     * Open the interactive session picker on the TUI (resume mode).
     *
     * Fetches sessions from HatfieldSessionStore::listSessions(),
     * builds a SelectListWidget with session display titles and IDs,
     * and mounts it via PickerOverlay.
     *
     * When the list is empty a status message is shown instead of
     * a picker, and the method returns without switching.
     *
     * Enter resumes the selected session via applySelectEffect().
     * d/D asks for Yes/No confirmation before deleting.
     *
     * @see openForRenameCommand() for the rename-mode variant
     */
    public function open(): void
    {
        $this->openWithOnSelect(
            self::RESUME_HEADER,
            function (SelectEvent $event): void {
                if ($this->confirmingDelete) {
                    $this->confirmDeleteSelection($event);

                    return;
                }

                $item = $event->getItem();
                $sessionId = $item['value'];

                // Close the picker overlay WITHOUT requesting a render.
                // applySelectEffect() → requestResume() cancels the run and
                // calls Tui::stop(), so a render at this point would paint a
                // torn-down widget tree and cause screen freeze / cursor
                // weirdness.
                // The Esc/cancel path uses closePicker(true) because it
                // stays in the same TUI session and needs the repaint.
                $this->closePicker(requestRender: false);
                $this->applySelectEffect($sessionId);
            },
            allowDelete: true,
        );
    }

    /**
     * Open the interactive session picker in rename-command-insertion mode.
     *
     * Same picker UI as open() but with rename-specific header text.
     * On select, inserts "/rename <id> " into the prompt editor instead
     * of switching sessions.  The user can then type a new name.
     */
    public function openForRenameCommand(): void
    {
        $this->openWithOnSelect(
            'Rename session — arrows move, Enter inserts command, Esc cancels',
            function (SelectEvent $event): void {
                $item = $event->getItem();
                $sessionId = $item['value'];

                // Insert the command text into the prompt editor
                // before closing so the cursor lands on the space
                // after the session id, ready for the new name.
                $screen = $this->screen;
                $screen->promptEditor()->replaceText('/rename '.$sessionId.' ');
                $screen->requestRender(true);

                $this->closePicker();
            },
            allowDelete: false,
        );
    }

    /**
     * Whether the picker is currently visible.
     */
    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    /**
     * Build picker items from session list rows (static, testable).
     *
     * Each item has the session ID as value and a single-column label
     * of the form "#<id> — <displayTitle>".  No description key is
     * included so SelectListWidget renders items at full width instead
     * of clamping the label column to min(30, maxLabelWidth).
     *
     * When {@see $selectedIndex} is provided, the matching row label is
     * wrapped in the accent theme colour so the highlighted entry is
     * visually consistent with CompletionMenu and ModelPickerController.
     *
     * @param list<array{sessionId: string, displayTitle: string, name: string, ...}> $sessions
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildItemsStatic(array $sessions, TuiTheme $theme, int $selectedIndex = -1): array
    {
        $items = [];

        foreach ($sessions as $i => $s) {
            $displayTitle = $s['displayTitle'] ?? $s['name'] ?? 'Session';
            $sessionId = $s['sessionId'];

            $label = \sprintf('#%s — %s', $sessionId, $displayTitle);

            if ($i === $selectedIndex) {
                $label = $theme->color(ThemeColorEnum::Accent, $label);
            }

            $items[] = [
                'value' => $sessionId,
                'label' => $label,
            ];
        }

        return $items;
    }

    /**
     * Execute session resume via the switch service.
     *
     * Calls {@see TuiSessionSwitchServiceInterface::requestResume()}
     * which cancels the current run, resets stateful singletons,
     * records the pending target, and stops the TUI event loop so
     * InteractiveMode rebuilds with the target session.
     *
     * @internal called from static closures within {@see open()}
     */
    public function applySelectEffect(string $sessionId): void
    {
        $this->switch->requestResume($sessionId);
    }

    /**
     * Close the picker overlay.
     *
     * Delegates to PickerOverlay::close() which removes the container
     * from the TUI and resets internal state. Also clears picker-scoped
     * confirm flags and transient session/error status lines so they do
     * not linger after the overlay closes.
     *
     * @param bool $requestRender Whether to schedule a TUI repaint.
     *                            Default true (Esc/cancel).  Pass false when the picker is
     *                            closing as part of a session-switch selection — the TUI is
     *                            about to stop and a render would paint torn-down state.
     */
    public function closePicker(bool $requestRender = true): void
    {
        $this->overlay?->close($requestRender);
        $this->overlay = null;
        $this->headerWidget = null;
        $this->sessions = [];
        $this->confirmingDelete = false;
        $this->pendingDeleteSessionId = null;
        $this->screen->setStatus('error', null);
        $this->screen->setStatus('session', null);
    }

    /**
     * Open the picker with a custom header text and on-select handler.
     *
     * Extracts the shared picker-building logic so both resume and
     * rename modes reuse the same overlay lifecycle, item-building,
     * navigation accent styling, and cancel handler.
     *
     * @param string   $headerText  Header widget text (muted style applied)
     * @param callable $onSelect    Callback receiving SelectEvent; called on Enter
     * @param bool     $allowDelete Whether d/D may start an in-picker delete confirm
     */
    private function openWithOnSelect(string $headerText, callable $onSelect, bool $allowDelete): void
    {
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }

        $sessions = $this->sessionStore->listSessions();

        if ([] === $sessions) {
            $this->screen->setStatus('session', 'No sessions found');
            $this->screen->refresh();

            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
        $this->sessions = $sessions;
        $this->confirmingDelete = false;
        $this->pendingDeleteSessionId = null;

        // ── Header ──
        $header = new TextWidget(
            text: $screen->theme()->muted($headerText),
            truncate: true,
        );
        $this->headerWidget = $header;

        // ── Keybindings ──
        $kb = SelectListKeybindings::standard();

        // ── Build items ──
        // Accent-colour the initially selected row (index 0) so the
        // picker is visually consistent with CompletionMenu and
        // ModelPickerController, which both use ThemeColorEnum::Accent
        // for the highlighted entry.  SelectListWidget's native
        // selected style (bold) layers on top.
        $theme = $screen->theme();
        $items = self::buildItemsStatic($sessions, $theme, selectedIndex: 0);

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: SelectListKeybindings::MAX_VISIBLE,
            keybindings: $kb,
        );

        // ── Arrows → rebuild items so the newly selected row gets accent colour ──
        // onSelectionChange fires only from cursor movement
        // (moveCursorUp/Down etc.), not from setItems() or
        // setSelectedIndex(), so there is no re-entrant loop.
        $picker = $this;
        $listWidget->onSelectionChange(
            static function (SelectionChangeEvent $event) use ($listWidget, $picker, $theme): void {
                if ($picker->confirmingDelete) {
                    return;
                }

                $selectedValue = $event->getItem()['value'];
                $selectedIdx = -1;

                foreach ($picker->sessions as $i => $s) {
                    if ($s['sessionId'] === $selectedValue) {
                        $selectedIdx = $i;

                        break;
                    }
                }

                $newItems = self::buildItemsStatic($picker->sessions, $theme, selectedIndex: $selectedIdx);
                $listWidget->setItems($newItems);
                $listWidget->setSelectedIndex(max(0, $selectedIdx));
            },
        );

        // ── Enter → call the on-select callback ──
        $listWidget->onSelect(static function (SelectEvent $event) use ($onSelect): void {
            $onSelect($event);
        });

        // ── Escape / Ctrl+C → cancel confirm or close without change ──
        $listWidget->onCancel(static function (CancelEvent $event) use ($picker, $listWidget): void {
            if ($picker->confirmingDelete) {
                $picker->exitConfirmMode($listWidget);

                return;
            }

            $picker->closePicker();
        });

        if ($allowDelete) {
            $listWidget->onInput(static function (string $data) use ($picker, $listWidget): bool {
                if ($picker->confirmingDelete) {
                    return false;
                }

                if ('d' !== $data && 'D' !== $data) {
                    return false;
                }

                $picker->beginDeleteConfirm($listWidget);

                return true;
            });
        }

        // ── Mount via PickerOverlay ──
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    private function beginDeleteConfirm(SelectListWidget $listWidget): void
    {
        $selected = $listWidget->getSelectedItem();
        if (null === $selected) {
            return;
        }

        $sessionId = (string) $selected['value'];
        $activeSessionId = $this->screen->sessionId();
        if ('' !== $activeSessionId && $sessionId === $activeSessionId) {
            $this->screen->setStatus('error', 'Cannot delete the current/active session');
            $this->screen->requestRender(true);

            return;
        }

        $match = array_find(
            $this->sessions,
            static fn (array $session): bool => $session['sessionId'] === $sessionId,
        );
        $title = $match['displayTitle'] ?? $match['name'] ?? 'Session';

        $this->confirmingDelete = true;
        $this->pendingDeleteSessionId = $sessionId;

        $header = $this->headerWidget;
        if (null !== $header) {
            $header->setText($this->screen->theme()->muted(
                \sprintf('Delete session #%s — %s?', $sessionId, $title),
            ));
        }

        $listWidget->setItems([
            ['value' => self::CONFIRM_YES, 'label' => $this->screen->theme()->color(ThemeColorEnum::Success, "\u{2713} Yes")],
            ['value' => self::CONFIRM_NO, 'label' => $this->screen->theme()->color(ThemeColorEnum::Error, "\u{2717} No")],
        ]);
        $this->screen->requestRender(true);
    }

    private function confirmDeleteSelection(SelectEvent $event): void
    {
        $listWidget = $this->overlay?->listWidget();
        if (null === $listWidget) {
            $this->confirmingDelete = false;
            $this->pendingDeleteSessionId = null;

            return;
        }

        $choice = (string) $event->getItem()['value'];
        if (self::CONFIRM_YES !== $choice) {
            $this->exitConfirmMode($listWidget);

            return;
        }

        $sessionId = $this->pendingDeleteSessionId;
        if (null === $sessionId || '' === $sessionId) {
            $this->exitConfirmMode($listWidget);

            return;
        }

        try {
            $this->sessionStore->deleteSession($sessionId);
        } catch (\RuntimeException) {
            $this->restoreSessionList($listWidget);
            $this->screen->setStatus('error', \sprintf('Session #%s no longer exists', $sessionId));
            $this->screen->requestRender(true);

            return;
        }

        $this->restoreSessionList($listWidget);
        $this->screen->setStatus('session', \sprintf('Deleted session #%s', $sessionId));
        $this->screen->requestRender(true);
    }

    private function exitConfirmMode(SelectListWidget $listWidget): void
    {
        $this->restoreSessionList($listWidget);
        $this->screen->requestRender(true);
    }

    private function restoreSessionList(SelectListWidget $listWidget): void
    {
        $this->confirmingDelete = false;
        $this->pendingDeleteSessionId = null;
        $this->sessions = $this->sessionStore->listSessions();

        $header = $this->headerWidget;
        if (null !== $header) {
            $header->setText($this->screen->theme()->muted(self::RESUME_HEADER));
        }

        $theme = $this->screen->theme();
        $listWidget->setItems(self::buildItemsStatic(
            $this->sessions,
            $theme,
            selectedIndex: [] === $this->sessions ? -1 : 0,
        ));
    }
}
