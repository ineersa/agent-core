<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Handler;

use Ineersa\HatfieldExt\ObservationalMemory\Message\ObserveBoundaryMessage;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmConflictException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Idempotent observation/coverage persistence for redelivery safety.
 */
#[AsMessageHandler]
final class ObserveBoundaryHandler
{
    public function __construct(
        private readonly ObservationRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ObserveBoundaryMessage $message): void
    {
        $rows = [];
        foreach ($message->observations as $index => $observation) {
            $content = $observation['content'];
            $contentHash = hash('sha256', $content);
            $observationId = $observation['observation_id']
                ?? hash('sha256', implode('|', [
                    $message->runId,
                    $message->boundaryKey,
                    $message->observerSchemaVersion,
                    (string) $index,
                    $contentHash,
                ]));

            $sourceRefs = $observation['source_refs'] ?? [];
            $rows[] = [
                'observation_id' => $observationId,
                'content' => $content,
                'content_hash' => $contentHash,
                'relevance' => (int) ($observation['relevance'] ?? 0),
                'token_count' => (int) ($observation['token_count'] ?? 0),
                'source_refs_json' => json_encode($sourceRefs, \JSON_THROW_ON_ERROR),
            ];
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        try {
            $result = $this->repository->commitBoundaryCoverage(
                coverageKey: $message->coverageKey(),
                runId: $message->runId,
                boundaryKey: $message->boundaryKey,
                sourceStartSeq: $message->sourceStartSeq,
                sourceEndSeq: $message->sourceEndSeq,
                sourceDigest: $message->sourceDigest,
                rendererVersion: $message->rendererVersion,
                observerSchemaVersion: $message->observerSchemaVersion,
                observerModel: $message->observerModel,
                observations: $rows,
                coveredAt: $now,
            );
        } catch (OmConflictException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }

        $this->logger->info('om.observe.committed', [
            'component' => 'observational_memory',
            'event_type' => 'om.observe.committed',
            'run_id' => $message->runId,
            'boundary_key' => $message->boundaryKey,
            'status' => $result['status'],
            'observation_count' => $result['observation_count'],
        ]);
    }
}
