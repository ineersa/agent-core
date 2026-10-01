<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SessionResumeProjectionDTO;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Shared disposable child transcript snapshot backed by cache.app.
 *
 * Freshness is the committed maxSeq on the snapshot. TTL only bounds cleanup.
 * Ordinary get/remember paths never scan the event archive.
 */
final class CacheChildRunTranscriptSnapshotStore implements ChildRunTranscriptSnapshotStoreInterface
{
    private const string CACHE_KEY_PREFIX = 'hatfield.child_transcript.snapshot.';
    private const string LOCK_KEY_PREFIX = 'hatfield-child-transcript-';
    private const int CLEANUP_TTL_SECONDS = 1800;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly LockFactory $lockFactory,
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
    ) {
    }

    public function get(string $runId): ChildRunTranscriptSnapshotDTO
    {
        return $this->find($runId)
            ?? throw new \RuntimeException(\sprintf('Child transcript snapshot missing for run %s; initialize via child-view startup before ordinary reuse.', $runId));
    }

    public function find(string $runId): ?ChildRunTranscriptSnapshotDTO
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $cached = $this->readCache($runId);
            if (null === $cached) {
                return null;
            }

            $this->writeCache($runId, $cached);

            return $cached;
        } finally {
            $lock->release();
        }
    }

    public function remember(string $runId, ChildRunTranscriptSnapshotDTO $snapshot): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $cached = $this->readCache($runId);
            if (null !== $cached && $cached->maxSeq > $snapshot->maxSeq) {
                return;
            }

            $this->writeCache($runId, $snapshot);
        } finally {
            $lock->release();
        }
    }

    private function readCache(string $runId): ?ChildRunTranscriptSnapshotDTO
    {
        $item = $this->pool->getItem(self::CACHE_KEY_PREFIX.$runId);
        if (!$item->isHit()) {
            return null;
        }

        $payload = $item->get();
        if (!\is_array($payload)) {
            throw new \RuntimeException(\sprintf('Invalid child transcript snapshot payload for run %s.', $runId));
        }

        try {
            $blocksRaw = $payload['transcript_blocks'] ?? null;
            $resumeRaw = $payload['resume'] ?? null;
            $pendingHumanRaw = $payload['pending_human_input_events'] ?? null;
            $pendingToolRaw = $payload['pending_tool_question_events'] ?? null;
            $maxSeq = $payload['max_seq'] ?? null;
            if (!\is_array($blocksRaw) || !\is_array($resumeRaw) || !\is_array($pendingHumanRaw) || !\is_array($pendingToolRaw) || !\is_int($maxSeq)) {
                throw new \RuntimeException(\sprintf('Invalid child transcript snapshot payload for run %s.', $runId));
            }

            /** @var list<TranscriptBlock> $blocks */
            $blocks = [];
            foreach ($blocksRaw as $blockRaw) {
                if (!\is_array($blockRaw)) {
                    throw new \RuntimeException(\sprintf('Invalid child transcript block payload for run %s.', $runId));
                }
                $block = $this->serializer->denormalize($blockRaw, TranscriptBlock::class);
                if (!$block instanceof TranscriptBlock) {
                    throw new \RuntimeException(\sprintf('Invalid child transcript block payload for run %s.', $runId));
                }
                $blocks[] = $block;
            }

            $resume = $this->serializer->denormalize($resumeRaw, SessionResumeProjectionDTO::class);
            if (!$resume instanceof SessionResumeProjectionDTO) {
                throw new \RuntimeException(\sprintf('Invalid child transcript resume payload for run %s.', $runId));
            }

            /** @var list<RuntimeEvent> $pendingHuman */
            $pendingHuman = [];
            foreach ($pendingHumanRaw as $eventRaw) {
                if (!\is_array($eventRaw)) {
                    throw new \RuntimeException(\sprintf('Invalid pending human overlay payload for run %s.', $runId));
                }
                $event = $this->serializer->denormalize($eventRaw, RuntimeEvent::class);
                if (!$event instanceof RuntimeEvent) {
                    throw new \RuntimeException(\sprintf('Invalid pending human overlay payload for run %s.', $runId));
                }
                $pendingHuman[] = $event;
            }

            /** @var list<RuntimeEvent> $pendingTool */
            $pendingTool = [];
            foreach ($pendingToolRaw as $eventRaw) {
                if (!\is_array($eventRaw)) {
                    throw new \RuntimeException(\sprintf('Invalid pending tool overlay payload for run %s.', $runId));
                }
                $event = $this->serializer->denormalize($eventRaw, RuntimeEvent::class);
                if (!$event instanceof RuntimeEvent) {
                    throw new \RuntimeException(\sprintf('Invalid pending tool overlay payload for run %s.', $runId));
                }
                $pendingTool[] = $event;
            }

            return new ChildRunTranscriptSnapshotDTO(
                transcriptBlocks: $blocks,
                resume: $resume,
                pendingHumanInputEvents: $pendingHuman,
                pendingToolQuestionEvents: $pendingTool,
                maxSeq: $maxSeq,
            );
        } catch (\Throwable $exception) {
            throw new \RuntimeException(\sprintf('Invalid child transcript snapshot payload for run %s.', $runId), previous: $exception);
        }
    }

    private function writeCache(string $runId, ChildRunTranscriptSnapshotDTO $snapshot): void
    {
        $item = $this->pool->getItem(self::CACHE_KEY_PREFIX.$runId);
        $item->set([
            'transcript_blocks' => array_map(
                fn (TranscriptBlock $block): mixed => $this->serializer->normalize($block),
                $snapshot->transcriptBlocks,
            ),
            'resume' => $this->serializer->normalize($snapshot->resume),
            'pending_human_input_events' => array_map(
                fn (RuntimeEvent $event): mixed => $this->serializer->normalize($event),
                $snapshot->pendingHumanInputEvents,
            ),
            'pending_tool_question_events' => array_map(
                fn (RuntimeEvent $event): mixed => $this->serializer->normalize($event),
                $snapshot->pendingToolQuestionEvents,
            ),
            'max_seq' => $snapshot->maxSeq,
        ]);
        $item->expiresAfter(self::CLEANUP_TTL_SECONDS);
        if (!$this->pool->save($item)) {
            throw new \RuntimeException(\sprintf('Cannot publish child transcript snapshot for run %s.', $runId));
        }
    }
}
