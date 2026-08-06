<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Tui\Picker\PickerListLabelFormatter;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Adapts {@see TuiRuntimeContext} to the public ExtensionApi TUI contract.
 */
final readonly class BridgeTuiExtensionContext implements TuiExtensionContextInterface
{
    public function __construct(
        private TuiRuntimeContext $runtime,
    ) {
    }

    public function getSessionId(): string
    {
        return $this->runtime->state->sessionId;
    }

    public function requestRender(bool $force = false): void
    {
        if ($force) {
            $this->runtime->screen->requestRender(true);

            return;
        }
        $this->runtime->tui->requestRender();
    }

    public function setStatus(string $key, ?string $text): void
    {
        $this->runtime->screen->setStatus($key, $text);
    }

    public function onTick(\Closure $listener): void
    {
        // Extensions must never force active 100Hz ticks; always return null.
        $this->runtime->ticks->add(static function () use ($listener): ?bool {
            $listener();

            return null;
        });
    }

    public function insertOverlayAfterEditor(AbstractWidget $widget): void
    {
        $this->runtime->screen->insertOverlayAfterEditor($widget);
    }

    public function removeOverlay(AbstractWidget $widget): void
    {
        $this->runtime->screen->removeOverlay($widget);
    }

    public function setFocus(AbstractWidget $widget): void
    {
        $this->runtime->tui->setFocus($widget);
    }

    public function formatMuted(string $text): string
    {
        return $this->runtime->screen->theme()->muted($text);
    }

    public function formatRolePrefix(string $displayRole): string
    {
        return PickerListLabelFormatter::formatRolePrefix($this->runtime->screen->theme(), $displayRole);
    }

    public function turnRowsInDisplayOrder(string $sessionId): array
    {
        $history = $this->runtime->historyProvider->forSession($sessionId);
        $rows = [];
        // Public ExtensionApi contract: retained user-prompt rows only.
        // Both /history and file /rewind consume this contract.
        foreach ($history->turns as $turn) {
            if ('user' !== $turn->displayRole) {
                continue;
            }
            $title = trim($turn->title);
            if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
                $title = 'Turn '.$turn->turnNo;
            }
            $rows[] = ['turnNo' => $turn->turnNo, 'title' => $title, 'displayRole' => $turn->displayRole];
        }

        return $rows;
    }
}
