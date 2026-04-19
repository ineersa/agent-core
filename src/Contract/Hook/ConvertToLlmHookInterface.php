<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\MessageBag;

/**
 * Defines the contract for converting raw message arrays into a structured MessageBag for Large Language Model processing. This interface allows implementations to transform and normalize input data while supporting cancellation via a CancellationToken.
 */
interface ConvertToLlmHookInterface
{
    /**
     * Transforms an array of messages into a MessageBag with optional cancellation support.
     *
     * @param list<AgentMessage> $messages
     */
    public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null): MessageBag;
}
