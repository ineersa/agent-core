<?php

declare(strict_types=1);

namespace Ineersa\AgentCore;

use Ineersa\AgentCore\DependencyInjection\AgentLoopExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle entry point that registers the AgentCore extension for container configuration and service loading.
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
