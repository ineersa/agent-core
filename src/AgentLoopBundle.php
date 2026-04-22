<?php

declare(strict_types=1);

namespace Ineersa\AgentCore;

use Ineersa\AgentCore\DependencyInjection\AgentLoopExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

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
