<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Public TUI surface for extension-owned overlays and status.
 */
interface TuiExtensionContextInterface
{
    public function getSessionId(): string;

    public function requestRender(bool $force = false): void;

    public function setStatus(string $key, ?string $text): void;

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
