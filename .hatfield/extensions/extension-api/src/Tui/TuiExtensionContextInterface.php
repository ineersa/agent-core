<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Public TUI surface for extension-owned overlays and status.
 *
 * Uses Symfony TUI widget types on purpose: project extensions under `.hatfield/extensions/`
 * implement {@see TuiExtensionInterface} and mount their own {@see AbstractWidget} trees
 * below the editor via the host bridge ({@see \Ineersa\Tui\Runtime\BridgeTuiExtensionContext}).
 *
 * Must stay free of Hatfield feature-specific DTOs and must not reference `Ineersa\Tui\*` internals.
 */
interface TuiExtensionContextInterface
{
    public function getSessionId(): string;

    public function requestRender(bool $force = false): void;

    /**
     * Set or remove a keyed status-panel row.
     *
     * Pass null to clear. Statuses are panel-only; footer content uses explicit
     * footer widget/segment-provider APIs, not this method.
     */
    public function setStatus(string $key, ?string $text): void;

    /**
     * Register an idle-safe TUI tick callback.
     *
     * Invoked via the host {@see \Ineersa\Tui\Runtime\TuiTickDispatcher}. The bridge
     * always discards the listener return value so extensions cannot request the
     * 100Hz busy tick cadence. Pollers that need work while idle must self-throttle
     * (Symfony TUI idle interval is ~250ms).
     *
     * @param \Closure(): mixed $listener
     */
    public function onTick(\Closure $listener): void;

    public function insertOverlayAfterEditor(AbstractWidget $widget): void;

    public function removeOverlay(AbstractWidget $widget): void;

    public function setFocus(AbstractWidget $widget): void;

    /** Muted transcript-style text using the active Hatfield theme. */
    public function formatMuted(string $text): string;

    /** Role prefix styling (user:, assistant:, etc.) for picker rows. */
    public function formatRolePrefix(string $displayRole): string;

    /**
     * Conversation turn rows in tree display order for interactive pickers.
     *
     * @return list<array{turnNo:int,title:string,displayRole:string}>
     */
    public function turnRowsInDisplayOrder(string $sessionId): array;
}
