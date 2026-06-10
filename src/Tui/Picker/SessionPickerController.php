<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Manages the interactive session picker overlay lifecycle.
 *
 * Opens an interactive SelectListWidget when invoked without
 * arguments.  Supports two modes:
 *   - Resume mode (open()): Enter resumes the selected session.
 *   - Rename mode (openForRenameCommand()): Enter inserts
 *     "/rename <id> " into the prompt editor; the user then
 *     types a new name and submits.
 *
 * Arrow keys navigate; Enter confirms; Escape cancels.
 *
 * Sessions are fetched fresh from HatfieldSessionStore on each
 * open so the picker always reflects the latest DB state.
 *
 * The controller is stateless between picker sessions — it creates
 * and destroys the SelectListWidget per invocation.
 */
final class SessionPickerController
{
    private ?PickerOverlay $overlay = null;

    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
        private readonly TuiSessionSwitchServiceInterface $switch,
    ) {
    }

    /**
     * Set the per-run TUI references that are only available at
     * listener registration time (called by SessionCommandRegistrar).
     *
     * The {@see TuiSessionState} reference is accepted to mirror the
     * pattern followed by other picker controllers and to guarantee
     * per-iteration runtime refs are all wired together.  It is not
     * currently read inside this picker but is reserved for future
     * use (e.g. highlighting the current session in the resume list).
     */
    public function setRuntimeRefs(Tui $tui, ChatScreen $screen, TuiSessionState $state): void
    {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->state = $state;
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
     *
     * @see openForRenameCommand() for the rename-mode variant
     */
    public function open(): void
    {
        $this->openWithOnSelect(
            'Resume session — arrows move, Enter resumes, Esc cancels',
            function (SelectEvent $event): void {
                $item = $event->getItem();
                $sessionId = $item['value'];

                $this->applySelectEffect($sessionId);
                $this->closePicker();
            },
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
                if (null !== $screen) {
                    $screen->promptEditor()->replaceText('/rename '.$sessionId.' ');
                    $screen->requestRender(true);
                }

                $this->closePicker();
            },
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
     * from the TUI and resets internal state.
     */
    public function closePicker(): void
    {
        $this->overlay?->close();
        $this->overlay = null;
    }

    /**
     * Open the picker with a custom header text and on-select handler.
     *
     * Extracts the shared picker-building logic so both resume and
     * rename modes reuse the same overlay lifecycle, item-building,
     * navigation accent styling, and cancel handler.
     *
     * @param string   $headerText Header widget text (muted style applied)
     * @param callable $onSelect   Callback receiving SelectEvent; called on Enter
     */
    private function openWithOnSelect(string $headerText, callable $onSelect): void
    {
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }

        if (null === $this->tui || null === $this->screen || null === $this->state) {
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

        // ── Header ──
        $header = new TextWidget(
            text: $screen->theme()->muted($headerText),
            truncate: true,
        );

        // ── Keybindings ──
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);

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
            maxVisible: 10,
            keybindings: $kb,
        );

        // ── Arrows → rebuild items so the newly selected row gets accent colour ──
        // onSelectionChange fires only from cursor movement
        // (moveCursorUp/Down etc.), not from setItems() or
        // setSelectedIndex(), so there is no re-entrant loop.
        $listWidget->onSelectionChange(
            static function (SelectionChangeEvent $event) use ($listWidget, $sessions, $theme): void {
                $selectedValue = $event->getItem()['value'];
                $selectedIdx = -1;

                foreach ($sessions as $i => $s) {
                    if ($s['sessionId'] === $selectedValue) {
                        $selectedIdx = $i;

                        break;
                    }
                }

                $newItems = self::buildItemsStatic($sessions, $theme, selectedIndex: $selectedIdx);
                $listWidget->setItems($newItems);
                $listWidget->setSelectedIndex(max(0, $selectedIdx));
            },
        );

        // ── Enter → call the on-select callback ──
        $listWidget->onSelect(static function (SelectEvent $event) use ($onSelect): void {
            $onSelect($event);
        });

        // ── Escape / Ctrl+C → close without change ──
        $picker = $this;
        $listWidget->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });

        // ── Mount via PickerOverlay ──
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }
}
