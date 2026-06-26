<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Pipeline\PendingSubagentCancellationMessageBuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

/**
 * Enriches synthetic subagent cancellation tool results using parent
 * subagent_progress snapshots and, as a fallback, artifact registry entries.
 */
final readonly class PendingSubagentCancellationMessageBuilder implements PendingSubagentCancellationMessageBuilderInterface
{
    private const string RETRIEVAL_HINT = 'Use agent_retrieve (metadata/events/history) for partial child details.';

    public function __construct(
        private EventStoreInterface $eventStore,
        private AgentArtifactRegistry $artifactRegistry,
    ) {
    }

    public function buildForPendingSubagent(
        string $parentRunId,
        string $toolCallId,
        ?array $toolCallInfo = null,
    ): ?string {
        $progress = $this->findLatestSubagentProgress($parentRunId, $toolCallId);
        if (null !== $progress) {
            $formatted = $this->formatFromProgress($progress);
            if ('' !== $formatted) {
                return $formatted;
            }
        }

        return $this->formatFromRegistryFallback($parentRunId, $toolCallInfo);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestSubagentProgress(string $parentRunId, string $toolCallId): ?array
    {
        $latest = null;
        $latestSeq = -1;

        foreach ($this->eventStore->allFor($parentRunId) as $event) {
            if (RunEventTypeEnum::ToolExecutionUpdate->value !== $event->type) {
                continue;
            }

            $payload = $event->payload;
            if (!\is_array($payload)) {
                continue;
            }

            if (($payload['tool_call_id'] ?? null) !== $toolCallId) {
                continue;
            }

            $progress = $payload['subagent_progress'] ?? null;
            if (!\is_array($progress)) {
                continue;
            }

            if ($event->seq > $latestSeq) {
                $latestSeq = $event->seq;
                $latest = $progress;
            }
        }

        return $latest;
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function formatFromProgress(array $progress): string
    {
        $mode = \is_string($progress['mode'] ?? null) ? $progress['mode'] : 'single';

        if ('parallel' === $mode) {
            return $this->formatParallelProgressCancellation($progress);
        }

        return $this->formatSingleProgressCancellation($progress);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function formatSingleProgressCancellation(array $progress): string
    {
        $agentName = \is_string($progress['agent_name'] ?? null) && '' !== $progress['agent_name']
            ? $progress['agent_name']
            : 'subagent';
        $artifactId = \is_string($progress['artifact_id'] ?? null) ? trim($progress['artifact_id']) : '';

        if ('' === $artifactId) {
            return '';
        }

        return implode("\n", [
            \sprintf('Subagent %s cancelled by parent run.', $agentName),
            'Artifact: '.$artifactId,
            'Status: cancelled',
            self::RETRIEVAL_HINT,
        ]);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function formatParallelProgressCancellation(array $progress): string
    {
        $children = $progress['children'] ?? null;
        if (!\is_array($children) || [] === $children) {
            return '';
        }

        $lines = ['Parallel subagent tool cancelled by parent run.', ''];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                continue;
            }

            $index = \is_int($child['index'] ?? null) ? $child['index'] : null;
            $agentName = \is_string($child['agent_name'] ?? null) && '' !== $child['agent_name']
                ? $child['agent_name']
                : 'subagent';
            $artifactId = \is_string($child['artifact_id'] ?? null) ? trim($child['artifact_id']) : '';
            if ('' === $artifactId) {
                continue;
            }

            $prefix = null !== $index ? \sprintf('#%d %s', $index, $agentName) : $agentName;
            $lines[] = \sprintf('%s — cancelled', $prefix);
            $lines[] = 'Artifact: '.$artifactId;
            $lines[] = '';
        }

        if (\count($lines) <= 2) {
            return '';
        }

        $lines[] = self::RETRIEVAL_HINT;

        return rtrim(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed>|null $toolCallInfo
     */
    private function formatFromRegistryFallback(string $parentRunId, ?array $toolCallInfo): ?string
    {
        try {
            $entries = $this->artifactRegistry->list($parentRunId);
        } catch (\Throwable) {
            return null;
        }

        $active = array_values(array_filter(
            $entries,
            static fn ($entry): bool => \in_array(
                $entry->status,
                [
                    AgentArtifactStatusEnum::Pending,
                    AgentArtifactStatusEnum::Running,
                    AgentArtifactStatusEnum::Cancelled,
                ],
                true,
            ),
        ));

        if (1 === \count($active)) {
            $entry = $active[0];

            return implode("\n", [
                \sprintf('Subagent %s cancelled by parent run.', $entry->agentName),
                'Artifact: '.$entry->artifactId,
                'Status: cancelled',
                self::RETRIEVAL_HINT,
            ]);
        }

        if (\count($active) > 1) {
            $lines = ['Parallel subagent tool cancelled by parent run.', ''];
            foreach ($active as $index => $entry) {
                $lines[] = \sprintf('#%d %s — cancelled', $index + 1, $entry->agentName);
                $lines[] = 'Artifact: '.$entry->artifactId;
                $lines[] = '';
            }
            $lines[] = self::RETRIEVAL_HINT;

            return rtrim(implode("\n", $lines));
        }

        $agentName = null;
        if (null !== $toolCallInfo && \is_string($toolCallInfo['name'] ?? null) && 'subagent' === $toolCallInfo['name']) {
            $args = $toolCallInfo['arguments'] ?? $toolCallInfo['args'] ?? null;
            if (\is_array($args) && \is_string($args['agent'] ?? null) && '' !== trim($args['agent'])) {
                $agentName = trim($args['agent']);
            }
        }

        if (null === $agentName) {
            return null;
        }

        return implode("\n", [
            \sprintf('Subagent %s cancelled by parent run.', $agentName),
            'Status: cancelled',
            self::RETRIEVAL_HINT,
        ]);
    }
}
