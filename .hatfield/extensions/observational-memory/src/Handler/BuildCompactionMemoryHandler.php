<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Handler;

use Ineersa\HatfieldExt\ObservationalMemory\Message\BuildCompactionMemoryMessage;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmConflictException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Idempotent compaction request/result persistence.
 */
final class BuildCompactionMemoryHandler
{
    public function __construct(
        private readonly CompactionRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BuildCompactionMemoryMessage $message): void
    {
        $reflections = [];
        foreach ($message->reflections as $index => $reflection) {
            $content = $reflection['content'];
            $reflectionId = $reflection['reflection_id']
                ?? hash('sha256', implode('|', [
                    $message->requestId,
                    $message->reflectorSchemaVersion,
                    (string) $index,
                    hash('sha256', $content),
                ]));
            $supporting = $reflection['supporting_observation_ids'] ?? [];
            $reflections[] = [
                'reflection_id' => $reflectionId,
                'content' => $content,
                'supporting_observation_ids_json' => json_encode($supporting, \JSON_THROW_ON_ERROR),
                'compression_level' => (string) ($reflection['compression_level'] ?? 'default'),
                'token_count' => (int) ($reflection['token_count'] ?? 0),
            ];
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $resultId = hash('sha256', $message->requestId.'|'.$message->observationSetHash);

        try {
            $result = $this->repository->commitResult(
                requestId: $message->requestId,
                resultId: $resultId,
                runId: $message->runId,
                requiredStartSeq: $message->requiredStartSeq,
                requiredEndSeq: $message->requiredEndSeq,
                requiredWatermark: $message->requiredWatermark,
                observationSetHash: $message->observationSetHash,
                status: $message->status,
                replacementText: $message->replacementText,
                reflectorModel: $message->reflectorModel,
                reflectorSchemaVersion: $message->reflectorSchemaVersion,
                reflections: $reflections,
                now: $now,
                failureCode: $message->failureCode,
            );
        } catch (OmConflictException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }

        $this->logger->info('om.compaction.committed', [
            'component' => 'observational_memory',
            'event_type' => 'om.compaction.committed',
            'run_id' => $message->runId,
            'request_id' => $message->requestId,
            'status' => $result['status'],
        ]);
    }
}
