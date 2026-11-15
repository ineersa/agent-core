<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Extension\ExtensionToolInvoker;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/** Constructor wiring only; existing fixtures do not exercise standalone invocation. */
trait ExtensionHostCapabilitiesFixtureTrait
{
    private function emptySessionStore(AppConfig $config): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            $config,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            new LockFactory(new InMemoryStore()),
        );
    }

    private function unusedToolInvoker(): ExtensionToolInvoker
    {
        return new ExtensionToolInvoker(
            static fn () => throw new \LogicException('Unused fixture capability.'),
            new StackToolExecutionContextAccessor(),
            $this->createStub(RunOperationalStatusReaderInterface::class),
            $this->createStub(EventStoreInterface::class),
            new ToolExecutionResultStore(),
            $this->createStub(ToolPayloadStoreInterface::class),
            $this->createStub(DenormalizerInterface::class),
            '',
            $this->createStub(ToolSetResolverInterface::class),
        );
    }
}
