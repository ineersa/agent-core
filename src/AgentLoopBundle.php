<?php

declare(strict_types=1);

namespace Ineersa\AgentCore;

use Ineersa\AgentCore\DependencyInjection\AgentLoopExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The AgentLoopBundle class serves as the Symfony bundle entry point for the AgentCore library, facilitating its integration into the application container. It provides the standard mechanism for registering the bundle's configuration and services within the Symfony framework.
 */
final class AgentLoopBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new AgentLoopExtension();
        }

        return $this->extension;
    }
}
