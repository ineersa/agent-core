<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\Tui\Picker\PickerListLabelFormatter;
use Ineersa\Tui\Picker\TreePickerController;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
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
        $tree = $this->runtime->turnTreeProvider->forSession($sessionId);
        $rows = [];
        foreach (TreePickerController::flattenTurnOrder($tree) as $turnNo) {
            $node = $tree->nodesByTurnNo[$turnNo] ?? null;
            if (!$node instanceof TurnTreeNodeView) {
                continue;
            }
            $title = trim($node->title);
            if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
                $title = trim($node->promptPreview);
            }
            if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
                $title = 'Turn '.$turnNo;
            }
            $rows[] = ['turnNo' => $turnNo, 'title' => $title, 'displayRole' => $node->displayRole];
        }

        return $rows;
    }
}
