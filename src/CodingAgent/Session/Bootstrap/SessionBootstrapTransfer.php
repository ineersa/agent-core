<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Bootstrap;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\JsonlPhysicalReadObservation;
use Psr\Log\LoggerInterface;

/** Stream private display bytes, then canonical records from the acknowledged byte cut. */
final readonly class SessionBootstrapTransfer
{
    public function __construct(private SessionBootstrapSpoolStore $spools, private RunHistorySourceProviderInterface $sources,
        private RuntimeEventMapper $mapper, private LoggerInterface $logger)
    {
    }

    /** @return \Generator<int, RuntimeEvent> */
    public function frames(SessionBootstrapDescriptorDTO $descriptor): \Generator
    {
        $index = 0;
        foreach ($this->spools->frames($descriptor) as $chunk) {
            yield new RuntimeEvent(RuntimeEventTypeEnum::BootstrapFrame->value, $descriptor->runId, 0,
                ['bootstrap_id' => $descriptor->bootstrapId, 'view_epoch' => $descriptor->viewEpoch, 'index' => $index++, 'data' => base64_encode($chunk)]);
        }
        yield new RuntimeEvent(RuntimeEventTypeEnum::BootstrapEnd->value, $descriptor->runId, 0, $descriptor->toArray() + ['frames' => $index]);
    }

    /** @return array{sequence: int, end_offset: int, anchor: int} */
    public function committedCut(string $runId): array
    {
        $source = $this->sources->historySource($runId);

        return $source->log->historyCut($source->path, $runId)
            ?? throw new \RuntimeException('Canonical archive disappeared during bootstrap.');
    }

    /** Each pass freezes a committed indexed cut. New commits require another suffix, never an event backlog.
     * @return \Generator<int, RuntimeEvent> */
    public function catchUp(SessionBootstrapDescriptorDTO $descriptor): \Generator
    {
        $source = $this->sources->historySource($descriptor->runId);
        $offset = $descriptor->endOffset;
        $sequence = $descriptor->canonicalSeq;
        do {
            $cut = $source->log->historyCut($source->path, $descriptor->runId);
            if (null === $cut || $cut['sequence'] < $sequence || $cut['end_offset'] < $offset) {
                throw new \RuntimeException('Canonical archive changed during bootstrap.');
            }
            $observation = new JsonlPhysicalReadObservation();
            try {
                foreach ($source->log->locatedLines($source->path, $offset, $observation, $cut['end_offset']) as $location) {
                    $record = $source->log->decodeLine($location['line']);
                    if (!str_ends_with($location['line'], "\n") || !\is_array($record) || ($record['run_id'] ?? null) !== $descriptor->runId
                        || !\is_int($record['seq'] ?? null) || $record['seq'] <= $sequence) {
                        throw new \RuntimeException('Invalid committed bootstrap suffix.');
                    }
                    $event = $source->log->denormalizeRunEvent($record);
                    if (null === $event || $source->log->isIncompatibleSchemaVersion($record)) {
                        throw new \RuntimeException('Bootstrap suffix event cannot be applied.');
                    }
                    $sequence = $event->seq;
                    $offset = $location['offset'] + $location['length'];
                    $runtime = $this->mapper->toRuntimeEvent($event);
                    unset($record, $event, $location);
                    if (null !== $runtime) {
                        yield $runtime;
                    }
                    unset($runtime);
                }
            } finally {
                $this->logger->debug('session.bootstrap.suffix_read', ['run_id' => $descriptor->runId, 'session_id' => $descriptor->runId,
                    'component' => 'session_bootstrap', 'event_type' => 'session.bootstrap.suffix_read', 'read_reason' => 'bootstrap_suffix',
                    'archive_bytes_read' => $observation->archiveBytesRead(), 'full_scan' => false]);
            }
            if ($offset !== $cut['end_offset'] || $sequence !== $cut['sequence']) {
                throw new \RuntimeException('Bootstrap suffix did not reach its committed cut.');
            }
            $latest = $source->log->historyCut($source->path, $descriptor->runId);
        } while (null !== $latest && $latest['sequence'] > $sequence);

        yield new RuntimeEvent(RuntimeEventTypeEnum::SessionReady->value, $descriptor->runId, 0,
            ['bootstrap_id' => $descriptor->bootstrapId, 'view_epoch' => $descriptor->viewEpoch, 'canonical_seq' => $sequence, 'end_offset' => $offset]);
    }
}
