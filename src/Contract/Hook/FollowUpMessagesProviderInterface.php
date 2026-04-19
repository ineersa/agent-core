<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Defines the contract for retrieving follow-up messages within the AgentCore hook system. This interface abstracts the source of contextual messages, allowing implementations to provide dynamic responses based on the current agent state.
 */
interface FollowUpMessagesProviderInterface
{
    /**
     * Retrieves an array of follow-up messages for the current context.
     *
     * @return list<AgentMessage>
     */
    public function getFollowUpMessages(): array;
}
