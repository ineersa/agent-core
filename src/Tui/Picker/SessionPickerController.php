<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Widget\SelectListKeybindings;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
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
 * open so the picker always reflects the latest DB state. They load in
 * pages of {@see PAGE_SIZE}; moving the selection within one visible
 * window of the last loaded row appends the next page in place.
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
    private const int PAGE_SIZE = 100;

    private ?PickerOverlay $overlay = null;
    private ?TextWidget $headerWidget = null;

    /** @var list<array{sessionId: string, displayTitle: string, name: string, ...}> */
    private array $sessions = [];

    /** @var array<string, int> sessionId → position in {@see} */
    private array $positions = [];

    private bool $exhausted = false;

    /** Row the list returns to after the delete confirmation. */
    private int $returnIndex = 0;

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
     * Fetches the first page of sessions from HatfieldSessionStore::listSessions(),
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
                $screen->requestRender();

                $this->closePicker();
            },
            allowDelete: false,
        );
    }

    /**
     * Build picker items from session list rows (static, testable).
     *
     * Each item has the session ID as value and a single-column label
     * of the form "#<id> — <displayTitle>".  No description key is
     * included so SelectListWidget renders items at full width instead
     * of clamping the label column to min(30, maxLabelWidth).
     *
     * The native selected-row style owns highlighting; labels stay plain.
     *
     * @param list<array{sessionId: string, displayTitle: string, name: string, ...}> $sessions
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildItemsStatic(array $sessions): array
    {
        $items = [];

        foreach ($sessions as $s) {
            $displayTitle = $s['displayTitle'] ?? $s['name'] ?? 'Session';
            $sessionId = $s['sessionId'];

            $label = \sprintf('#%s — %s', $sessionId, $displayTitle);

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
        $this->positions = [];
        $this->exhausted = false;
        $this->returnIndex = 0;
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

        $this->loadSessions(self::PAGE_SIZE);

        if ([] === $this->sessions) {
            $this->screen->setStatus('session', 'No sessions found');
            $this->screen->refresh();

            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
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

        // Preserve the session picker accent without rebuilding labels on arrows.
        $tui->addStyleSheet(new StyleSheet([
            '.session-picker::selected' => new Style(color: $screen->theme()->getPalette()->get(ThemeColorEnum::Accent)),
        ]));

        $listWidget = new SelectListWidget(
            items: self::buildItemsStatic($this->sessions),
            maxVisible: SelectListKeybindings::MAX_VISIBLE,
            keybindings: $kb,
        );
        $listWidget->addStyleClass('session-picker');

        $picker = $this;

        // ── Enter → call the on-select callback ──
        $listWidget->onSelect(static function (SelectEvent $event) use ($onSelect): void {
            $onSelect($event);
        });

        $listWidget->onSelectionChange(static function (SelectionChangeEvent $event) use ($picker, $listWidget): void {
            $picker->loadNextPageNear($listWidget, (string) $event->getItem()['value']);
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
            $this->screen->requestRender();

            return;
        }

        $match = array_find(
            $this->sessions,
            static fn (array $session): bool => $session['sessionId'] === $sessionId,
        );
        $title = $match['displayTitle'] ?? $match['name'] ?? 'Session';

        $this->confirmingDelete = true;
        $this->pendingDeleteSessionId = $sessionId;
        $this->returnIndex = $this->positions[$sessionId] ?? 0;

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
        $this->screen->requestRender();
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
            $this->screen->requestRender();

            return;
        }

        $this->restoreSessionList($listWidget);
        $this->screen->setStatus('session', \sprintf('Deleted session #%s', $sessionId));
        $this->screen->requestRender();
    }

    private function exitConfirmMode(SelectListWidget $listWidget): void
    {
        $this->restoreSessionList($listWidget);
        $this->screen->requestRender();
    }

    private function restoreSessionList(SelectListWidget $listWidget): void
    {
        $this->confirmingDelete = false;
        $this->pendingDeleteSessionId = null;
        // Reload as many rows as were already browsed so the restored list
        // keeps the confirmed row's position instead of jumping to the top.
        $this->loadSessions(max(self::PAGE_SIZE, \count($this->sessions)));

        $header = $this->headerWidget;
        if (null !== $header) {
            $header->setText($this->screen->theme()->muted(self::RESUME_HEADER));
        }

        $listWidget->setItems(self::buildItemsStatic($this->sessions));
        $listWidget->setSelectedIndex($this->returnIndex);
    }

    private function loadNextPageNear(SelectListWidget $listWidget, string $selectedSessionId): void
    {
        $index = $this->positions[$selectedSessionId] ?? null;
        if ($this->confirmingDelete || $this->exhausted || null === $index
            || $index < \count($this->sessions) - SelectListKeybindings::MAX_VISIBLE) {
            return;
        }

        $page = $this->sessionStore->listSessions(limit: self::PAGE_SIZE, offset: \count($this->sessions));
        $this->exhausted = \count($page) < self::PAGE_SIZE;
        // Offsets shift when another process creates a session meanwhile; skip rows already shown.
        foreach ($page as $session) {
            if (!isset($this->positions[$session['sessionId']])) {
                $this->positions[$session['sessionId']] = \count($this->sessions);
                $this->sessions[] = $session;
            }
        }

        $listWidget->setItems(self::buildItemsStatic($this->sessions));
        $listWidget->setSelectedIndex($index);
    }

    private function loadSessions(int $limit): void
    {
        $this->sessions = $this->sessionStore->listSessions(limit: $limit);
        $this->exhausted = \count($this->sessions) < $limit;
        $this->positions = array_flip(array_column($this->sessions, 'sessionId'));
    }
}
