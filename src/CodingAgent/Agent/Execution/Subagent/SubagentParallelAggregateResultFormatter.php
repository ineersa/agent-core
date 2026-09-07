<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;

use function Symfony\Component\String\u;

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

        return $this->limitInlineHandoffs(rtrim(implode("\n", $lines)), $sorted);
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

        return $this->limitInlineHandoffs($body."\n\nUse agent_retrieve (metadata/events/history) for partial child details.", $sorted);
    }

    /** @param list<ChildRunBatchItemSnapshotDTO> $items */
    private function limitInlineHandoffs(string $text, array $items): string
    {
        if (u($text)->length() <= 50000) {
            return $text;
        }

        $lines = ['Parallel subagent response exceeds 50,000 characters. Inline handoffs omitted.', ''];
        foreach ($items as $item) {
            $lines[] = \sprintf('#%d %s', $item->identity->batchIndex, $item->artifactStatus->value ?? 'unknown');
            $lines[] = 'Artifact: '.$item->identity->artifactId;
        }
        $lines[] = '';
        $lines[] = 'Use agent_retrieve with an artifact_id to inspect each handoff.';

        return implode("\n", $lines);
    }
}
