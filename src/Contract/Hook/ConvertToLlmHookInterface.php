<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\MessageBag;

interface ConvertToLlmHookInterface
{
    /**
     * Transforms an array of messages into a MessageBag with optional cancellation support.
     *
     * @param list<AgentMessage> $messages
     */
    public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null): MessageBag;
}
