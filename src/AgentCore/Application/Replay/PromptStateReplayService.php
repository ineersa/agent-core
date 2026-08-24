<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Replay;

final readonly class PromptStateReplayService
{
    /**
     * @param list<array<string, mixed>> $messages
     */
    public function estimateTokens(array $messages): int
    {
        $encoded = json_encode($messages);

        if (false === $encoded) {
            return 0;
        }

        return (int) ceil(\strlen($encoded) / 4);
    }
}
