<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Close owned Codex WebSocket cache entries when the llm Messenger worker stops.
 */
#[AsEventListener(event: WorkerStoppedEvent::class)]
final readonly class CodexWebSocketWorkerShutdownSubscriber
{
    public function __construct(
        private CodexWebSocketConnectionCache $connectionCache,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerStoppedEvent $event): void
    {
        $transportNames = $event->getWorker()->getMetadata()->getTransportNames();
        if (!\in_array('llm', $transportNames, true)) {
            return;
        }

        $sessionId = $_SERVER['HATFIELD_SESSION_ID'] ?? $_ENV['HATFIELD_SESSION_ID'] ?? 'unknown';
        if ('' !== $sessionId && 'unknown' !== $sessionId) {
            $this->connectionCache->closeSession($sessionId);
        }

        $this->connectionCache->closeAll();

        $this->logger->info('codex.websocket.worker.shutdown', [
            'event_type' => 'codex.websocket.worker.shutdown',
            'component' => 'codex_websocket_worker_shutdown_subscriber',
            'session_id' => '' !== $sessionId && 'unknown' !== $sessionId ? $sessionId : 'unknown',
        ]);
    }
}
