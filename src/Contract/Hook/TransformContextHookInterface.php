<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

interface TransformContextHookInterface
{
    /**
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array;
}
