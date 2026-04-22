<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Transforms message payloads in the agent loop with optional cancellation support.
 */
interface TransformContextHookInterface
{
    /**
     * Transforms an array of messages with optional cancellation support.
     *
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array;
}
