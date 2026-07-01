<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

use Ineersa\AgentCore\Application\Handler\RunRewindService;

final class RunRewindServiceAdapter implements ConversationRewindInterface
{
    public function __construct(private readonly RunRewindService $inner)
    {
    }

    public function rewind(string $runId, int $targetTurnNo): array
    {
        return $this->inner->rewind($runId, $targetTurnNo);
    }
}
