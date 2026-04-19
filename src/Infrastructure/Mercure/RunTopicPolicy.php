<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Mercure;

final readonly class RunTopicPolicy
{
    public function topicFor(string $runId): string
    {
        return \sprintf('agent/runs/%s', $runId);
    }

    /**
     * @return list<string>
     */
    public function topicsFor(string $runId): array
    {
        return [$this->topicFor($runId)];
    }
}
