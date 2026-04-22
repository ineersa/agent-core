<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

interface SteeringMessagesProviderInterface
{
    /**
     * Returns an array of steering messages for agent configuration.
     *
     * @return list<AgentMessage>
     */
    public function getSteeringMessages(): array;
}
