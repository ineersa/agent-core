<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\MessageBag;

interface ConvertToLlmHookInterface
{
    /**
     * @param list<AgentMessage> $messages
     */
    public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null): MessageBag;
}
