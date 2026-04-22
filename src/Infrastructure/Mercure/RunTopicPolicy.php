<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Mercure;

/**
 * Constructs Mercure topic strings for run event publication and subscription.
 */
final readonly class RunTopicPolicy
{
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
