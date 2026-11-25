<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundChildStatusDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionActivityContextInterface;
use Ineersa\Tui\Picker\PickerListLabelFormatter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Adapts {@see TuiRuntimeContext} to the public ExtensionApi TUI contract.
 */
final class BridgeTuiExtensionContext implements TuiSessionActivityContextInterface
{
    private ?TuiBackgroundSnapshotDTO $backgroundSample = null;

    public function __construct(
        private readonly TuiRuntimeContext $runtime,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getSessionId(): string
    {
        return $this->runtime->state->sessionId;
    }

    public function getSessionDisplayTitle(): ?string
    {
        return $this->runtime->sessionStore->findDisplayTitle($this->getSessionId());
    }

    public function isMainActive(): ?bool
    {
        $state = $this->runtime->state;
        if (!$this->canReadSelectedSession() || (null === $state->handle && $state->activity->isActive())) {
            return null;
        }

        return $state->activity->isActive();
    }

    public function getBackgroundAgents(): ?int
    {
        return $this->getBackgroundSnapshot()->activeSubagents;
    }

    public function getBackgroundJobs(): ?int
    {
        return $this->getBackgroundSnapshot()->backgroundScripts;
    }

    public function getBackgroundSnapshot(bool $refresh = true): TuiBackgroundSnapshotDTO
    {
        $sessionId = $this->getSessionId();
        if (!$this->canReadSelectedSession() || $this->backgroundSample?->sessionId !== $sessionId) {
            $this->backgroundSample = null;
        }
        if (!$this->canReadSelectedSession()) {
            return new TuiBackgroundSnapshotDTO($sessionId, null, null);
        }

        if (!$refresh) {
            return $this->backgroundSample ?? new TuiBackgroundSnapshotDTO($sessionId, null, null);
        }

        $this->backgroundSample = null;
        try {
            $snapshot = $this->runtime->client->backgroundWork($sessionId, observedChildRunIds: $this->runtime->state->subagentLiveCatalog->activeRunIds());
        } catch (\Throwable $exception) {
            $this->logger->warning('tui_extension.background_inventory_unavailable', [
                'component' => 'tui_extension', 'event_type' => 'background_inventory_unavailable',
                'run_id' => $sessionId, 'session_id' => $sessionId, 'exception_class' => $exception::class,
            ]);

            return new TuiBackgroundSnapshotDTO($sessionId, null, null);
        }
        if ($this->getSessionId() !== $sessionId || !$this->canReadSelectedSession()) {
            return new TuiBackgroundSnapshotDTO($sessionId, null, null);
        }

        return $this->backgroundSample = new TuiBackgroundSnapshotDTO(
            $sessionId,
            \count(array_filter($snapshot->children, static fn (BackgroundChildStatusDTO $child): bool => !SubagentLiveStatusEnum::fromProgressString($child->status)->isTerminal())),
            \count(array_filter($snapshot->work, static fn (BackgroundWorkDTO $work): bool => !$work->terminal && \in_array($work->kind, ['bash', 'code_mode'], true))),
            $snapshot->schedules,
        );
    }

    public function rememberBackgroundSample(TuiBackgroundSnapshotDTO $snapshot): void
    {
        if ($snapshot->sessionId === $this->getSessionId() && $this->canReadSelectedSession()) {
            $this->backgroundSample = $snapshot;
        }
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

    public function setTransientStatus(string $key, string $text): void
    {
        $this->runtime->screen->setTransientStatus($key, $text);
    }

    public function setExtensionWarning(string $name, ?string $message): void
    {
        $this->runtime->screen->setExtensionWarning($name, $message);
        $this->runtime->tui->requestRender();
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
        $rows = [];
        // The published ExtensionApi returns a complete array. Keep that explicit
        // request separate from the native /history picker's bounded page lifetime.
        $after = 0;
        do {
            $history = $this->runtime->historyProvider->forSession($sessionId, after: $after);
            foreach ($history->entries as $prompt) {
                if ('user' !== $prompt->role) {
                    continue;
                }
                $title = PickerListLabelFormatter::sanitizeTitle($this->runtime->historyProvider->promptText($sessionId, $prompt->turnNo));
                if ('' === $title) {
                    $title = 'Turn '.$prompt->turnNo;
                }
                $rows[] = ['turnNo' => $prompt->turnNo, 'title' => $title, 'displayRole' => 'user'];
            }
            $after = $history->newerAfter;
        } while (null !== $after);

        return $rows;
    }

    private function canReadSelectedSession(): bool
    {
        $state = $this->runtime->state;

        return $state->sessionReady && '' !== $state->sessionId && (null === $state->handle || $state->handle->runId === $state->sessionId);
    }
}
