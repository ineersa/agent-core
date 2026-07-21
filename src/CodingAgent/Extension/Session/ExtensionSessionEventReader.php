<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Session\SessionExistenceCheckerInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderException;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * CodingAgent adapter for public SessionEventReaderInterface.
 *
 * Wraps EventStoreInterface::allFor() and filters an inclusive seq range into
 * immutable public DTOs. Missing session is distinct from an empty range.
 *
 * @internal
 */
final readonly class ExtensionSessionEventReader implements SessionEventReaderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private SessionExistenceCheckerInterface $sessionStore,
        private LoggerInterface $logger,
    ) {
    }

    public function readRange(string $runId, int $startSeq, int $endSeq): array
    {
        if ('' === $runId) {
            throw SessionEventReaderException::invalidRange($runId, $startSeq, $endSeq);
        }
        if ($startSeq < 1 || $endSeq < $startSeq) {
            throw SessionEventReaderException::invalidRange($runId, $startSeq, $endSeq);
        }

        if (!$this->sessionStore->exists($runId)) {
            throw SessionEventReaderException::missingSession($runId);
        }

        try {
            $events = $this->eventStore->allFor($runId);
        } catch (\Throwable $e) {
            $this->logger->warning('extension.session_event_read_failed', [
                'component' => 'extension_session_event_reader',
                'event_type' => 'session_event_read_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'start_seq' => $startSeq,
                'end_seq' => $endSeq,
                'exception_class' => $e::class,
            ]);

            throw SessionEventReaderException::readFailed($runId, 'canonical event store read failed', $e);
        }

        $result = [];
        foreach ($events as $event) {
            if ($event->seq < $startSeq || $event->seq > $endSeq) {
                continue;
            }
            $result[] = new SessionEventDTO(
                runId: $event->runId,
                seq: $event->seq,
                turnNo: $event->turnNo,
                type: $event->type,
                payload: $event->payload,
                createdAt: $event->createdAt,
            );
        }

        return $result;
    }
}
