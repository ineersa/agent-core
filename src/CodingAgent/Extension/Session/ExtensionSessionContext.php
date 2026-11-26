<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Session;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundChildStatusDTO;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkDTO;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Hatfield\ExtensionApi\Session\DTO\BackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Session\SessionContextInterface;
use Psr\Log\LoggerInterface;

final readonly class ExtensionSessionContext implements SessionContextInterface
{
    public function __construct(
        private ExtensionSessionEventPublisher $events,
        private HatfieldSessionStore $sessionStore,
        private BackgroundWorkSnapshotProvider $backgroundWork,
        private LoggerInterface $logger,
    ) {
    }

    public function getSessionId(): string
    {
        return $this->events->sessionId();
    }

    public function getSessionDisplayTitle(): ?string
    {
        $sessionId = $this->getSessionId();

        return '' === $sessionId ? null : $this->sessionStore->findDisplayTitle($sessionId);
    }

    public function getBackgroundSnapshot(): BackgroundSnapshotDTO
    {
        $sessionId = $this->getSessionId();
        if ('' === $sessionId) {
            return new BackgroundSnapshotDTO($sessionId, null, null);
        }
        try {
            $snapshot = $this->backgroundWork->snapshot($sessionId);
        } catch (\Throwable $exception) {
            $this->logger->warning('extension.background_inventory_unavailable', [
                'component' => 'extension_session_context', 'event_type' => 'background_inventory_unavailable',
                'run_id' => $sessionId, 'session_id' => $sessionId, 'exception_class' => $exception::class,
            ]);

            return new BackgroundSnapshotDTO($sessionId, null, null);
        }

        return new BackgroundSnapshotDTO(
            $sessionId,
            \count(array_filter($snapshot->children, static fn (BackgroundChildStatusDTO $child): bool => !(RunStatus::tryFrom($child->status)?->isTerminal() ?? false))),
            \count(array_filter($snapshot->work, static fn (BackgroundWorkDTO $work): bool => !$work->terminal && \in_array($work->kind, ['bash', 'code_mode'], true))),
            $snapshot->schedules,
        );
    }
}
