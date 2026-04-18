<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

interface FollowUpMessagesProviderInterface
{
    /**
     * @return list<AgentMessage>
     */
    public function getFollowUpMessages(): array;
}
