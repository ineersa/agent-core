<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Defines the contract for retrieving steering messages within the AgentCore hook system. This interface abstracts the source of configuration or routing directives used to steer agent behavior. It serves as a dependency injection point for components requiring dynamic message resolution.
 */
interface SteeringMessagesProviderInterface
{
    /**
     * Returns an array of steering messages for agent configuration.
     *
     * @return list<AgentMessage>
     */
    public function getSteeringMessages(): array;
}
