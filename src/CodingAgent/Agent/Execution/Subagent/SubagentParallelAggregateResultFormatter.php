<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;

final class SubagentParallelAggregateResultFormatter
{
    public function formatSuccess(ChildRunBatchSupervisionResultDTO $result): string
    {
        $sorted = $result->items;
        usort($sorted, static fn (ChildRunBatchItemSnapshotDTO $a, ChildRunBatchItemSnapshotDTO $b): int => $a->identity->batchIndex <=> $b->identity->batchIndex);
        $count = \count($sorted);
        $lines = [\sprintf('Parallel subagents completed (%d/%d).', $count, $count), ''];
        foreach ($sorted as $item) {
            $id = $item->identity;
            $lines[] = \sprintf('#%d %s — completed', $id->batchIndex, $id->displayName);
            $lines[] = 'Artifact: '.$id->artifactId;
            if ('' !== $item->message) {
                $lines[] = trim($item->message);
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    public function formatReport(ChildRunBatchSupervisionResultDTO $result): string
    {
        $sorted = $result->items;
        usort($sorted, static fn (ChildRunBatchItemSnapshotDTO $a, ChildRunBatchItemSnapshotDTO $b): int => $a->identity->batchIndex <=> $b->identity->batchIndex);
        $lines = [];
        foreach ($sorted as $item) {
            $id = $item->identity;
            $status = null !== $item->artifactStatus ? $item->artifactStatus->value : 'unknown';
            $lines[] = \sprintf('#%d %s — %s', $id->batchIndex, $id->displayName, $status);
            $lines[] = 'Artifact: '.$id->artifactId;
            if ('' !== $item->message) {
                $lines[] = trim($item->message);
            }
            $lines[] = '';
        }

        $body = rtrim(implode("\n", $lines));
        if ('' === $body) {
            return 'Use agent_retrieve (metadata/events/history) for partial child details.';
        }

        return $body."\n\nUse agent_retrieve (metadata/events/history) for partial child details.";
    }

    public function hasFailures(ChildRunBatchSupervisionResultDTO $result): bool
    {
        foreach ($result->items as $item) {
            if (AgentArtifactStatusEnum::Completed !== $item->artifactStatus) {
                return true;
            }
        }

        return false;
    }
}
