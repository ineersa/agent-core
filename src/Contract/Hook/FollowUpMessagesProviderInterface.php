<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Supplies contextual follow-up messages based on current agent state.
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
