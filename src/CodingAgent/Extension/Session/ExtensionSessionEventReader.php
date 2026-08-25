<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderException;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * Recovery/compaction-only adapter over the canonical EventStore.
 *
 * Reads the requested range without materializing the complete events.jsonl.
 * Must not be used on every turn or boundary; AfterTurnCommit already exposes
 * the committed batch.
 */
final readonly class ExtensionSessionEventReader implements SessionEventReaderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private LoggerInterface $logger,
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
            foreach ($this->eventStore->rangeFor($runId, $startSeq, $endSeq) as $event) {
                yield new SessionEventDTO(
                    runId: $event->runId,
                    seq: $event->seq,
                    turnNo: $event->turnNo,
                    type: $event->type,
                    payload: $event->payload,
                    createdAt: $event->createdAt->format(\DateTimeInterface::ATOM),
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('extension.session_event_reader.read_failed', [
                'component' => 'extension_session_event_reader',
                'event_type' => 'extension.session_event_reader.read_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'start_seq' => $startSeq,
                'end_seq' => $endSeq,
                // Privacy: log exception class only; store errors may include path/content fragments.
                'exception_class' => $e::class,
            ]);

            throw SessionEventReaderException::readFailed($runId, 'event store read failed.');
        }
    }
}
