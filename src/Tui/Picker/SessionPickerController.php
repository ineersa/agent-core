<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Manages the interactive session picker overlay lifecycle.
 *
 * Opens an interactive SelectListWidget when /resume is invoked
 * without arguments.  Arrow keys navigate; Enter resumes the
 * selected session; Escape cancels without switching.
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
     */
    public function setRuntimeRefs(Tui $tui, ChatScreen $screen, TuiSessionState $state): void
    {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->state = $state;
    }

    /**
     * Open the interactive session picker on the TUI.
     *
     * Fetches sessions from HatfieldSessionStore::listSessions(),
     * builds a SelectListWidget with session display titles and IDs,
     * and mounts it via PickerOverlay.
     *
     * When the list is empty a status message is shown instead of
     * a picker, and the method returns without switching.
     */
    public function open(): void
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
            text: $screen->theme()->muted('Resume session — arrows move, Enter resumes, Esc cancels'),
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
        $items = self::buildItemsStatic($sessions, $screen->theme());

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );

        // ── Enter → resume selected session, close ──
        $controller = $this;

        $listWidget->onSelect(static function (SelectEvent $event) use ($controller): void {
            $item = $event->getItem();
            $sessionId = $item['value'];

            $controller->applySelectEffect($sessionId);
            $controller->closePicker();
        });

        // ── Escape / Ctrl+C → close without change ──
        $listWidget->onCancel(static function (CancelEvent $event) use ($controller): void {
            $controller->closePicker();
        });

        // ── Mount via PickerOverlay ──
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
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
     * Each item has the session ID as value and a label showing
     * the display title with a muted session-ID suffix so users
     * can distinguish sessions with similar names.
     *
     * @param list<array{sessionId: string, displayTitle: string, name: string, ...}> $sessions
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildItemsStatic(array $sessions, TuiTheme $theme): array
    {
        $items = [];

        foreach ($sessions as $s) {
            $displayTitle = $s['displayTitle'] ?? $s['name'] ?? 'Session';
            $sessionId = $s['sessionId'];

            $label = \sprintf(
                '  %s  %s',
                $displayTitle,
                $theme->muted(\sprintf('#%s', $sessionId)),
            );

            $items[] = [
                'value' => $sessionId,
                'label' => $label,
            ];
        }

        return $items;
    }

    /**
     * Find the index of a value in the items array.
     *
     * @param list<array{value: string, label?: string}> $items
     */
    public static function findItemIndex(array $items, string $value): ?int
    {
        foreach ($items as $i => $item) {
            if ($item['value'] === $value) {
                return $i;
            }
        }

        return null;
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
}
