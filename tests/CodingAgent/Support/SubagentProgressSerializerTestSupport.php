<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Runtime\SubagentLiveCatalog;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Thin test accessors over AttributeSerializerValidatorTestFactory for subagent_progress.
 */
final class SubagentProgressSerializerTestSupport
{
    /**
     * @return array{0: SerializerInterface&NormalizerInterface&DenormalizerInterface, 1: ValidatorInterface}
     */
    public static function stack(): array
    {
        return AttributeSerializerValidatorTestFactory::create();
    }

    public static function denormalizer(): DenormalizerInterface
    {
        return self::stack()[0];
    }

    public static function normalizer(): NormalizerInterface
    {
        return self::stack()[0];
    }

    public static function validator(): ValidatorInterface
    {
        return self::stack()[1];
    }

    public static function ingestCatalogEvent(SubagentLiveCatalog $catalog, RuntimeEvent $event): void
    {
        /** @var SubagentProgressSnapshotInterface $snapshot */
        $snapshot = self::denormalizer()->denormalize(
            $event->payload['subagent_progress'],
            SubagentProgressSnapshotInterface::class,
        );
        $catalog->ingestSnapshot($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public static function canonicalSingleWire(
        string $agentName = 'scout',
        string $artifactId = 'agent_abc',
        string $agentRunId = 'child-run-1',
        string $taskSummary = 'Inspect TUI',
        string $model = 'test/model',
        string $reasoning = 'medium',
        string $status = 'running',
        int $turnNo = 1,
        int $elapsedMs = 100,
    ): array {
        return [
            'mode' => 'single',
            'status' => $status,
            'agent_name' => $agentName,
            'artifact_id' => $artifactId,
            'agent_run_id' => $agentRunId,
            'task_summary' => $taskSummary,
            'model' => $model,
            'reasoning' => $reasoning,
            'turn_no' => $turnNo,
            'elapsed_ms' => $elapsedMs,
            'tool_count' => 0,
            'llm_step_count' => 0,
            'input_tokens' => 0,
            'latest_input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'total_tokens' => 0,
            'recent_tools' => [],
        ];
    }
}
