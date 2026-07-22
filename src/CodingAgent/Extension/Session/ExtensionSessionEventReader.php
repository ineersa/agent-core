<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderException;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Recovery/compaction-only adapter over the canonical EventStore.
 *
 * May scan the full events.jsonl for a run. Must not be used on the hot
 * turn/boundary path — AfterTurnCommit already exposes the committed batch.
 */
final readonly class ExtensionSessionEventReader implements SessionEventReaderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function readRange(string $runId, int $startSeq, int $endSeq): iterable
    {
        if ('' === trim($runId)) {
            throw new \InvalidArgumentException('runId must be a non-empty string.');
        }

        if ($startSeq < 1 || $endSeq < 1) {
            throw new \InvalidArgumentException('startSeq and endSeq must be positive integers.');
        }

        if ($endSeq < $startSeq) {
            return [];
        }

        try {
            $events = $this->eventStore->allFor($runId);
        } catch (\Throwable $e) {
            $this->logger->error('extension.session_event_reader.read_failed', [
                'component' => 'extension_session_event_reader',
                'event_type' => 'extension.session_event_reader.read_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'start_seq' => $startSeq,
                'end_seq' => $endSeq,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            throw SessionEventReaderException::readFailed($runId, 'event store read failed.');
        }

        foreach ($events as $event) {
            if ($event->seq < $startSeq || $event->seq > $endSeq) {
                continue;
            }

            yield new SessionEventDTO(
                runId: $event->runId,
                seq: $event->seq,
                turnNo: $event->turnNo,
                type: $event->type,
                payload: $event->payload,
                createdAt: $event->createdAt->format(\DateTimeInterface::ATOM),
            );
        }
    }
}
