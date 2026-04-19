<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Mercure;

/**
 * This class provides utility methods for constructing Mercure topic identifiers based on a run identifier. It encapsulates the logic for generating single and multiple topic strings required for event publication or subscription within the Mercure transport boundary.
 */
final readonly class RunTopicPolicy
{
    /**
     * Generates a single Mercure topic string for the specified run ID.
     */
    public function topicFor(string $runId): string
    {
        return \sprintf('agent/runs/%s', $runId);
    }

    /**
     * Generates an array of Mercure topic strings for the specified run ID.
     *
     * @return list<string>
     */
    public function topicsFor(string $runId): array
    {
        return [$this->topicFor($runId)];
    }
}
