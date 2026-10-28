<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\CodingAgent\Extension\ExtensionToolInvoker;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/** Constructor wiring only; existing fixtures do not exercise standalone invocation. */
trait ExtensionHostCapabilitiesFixtureTrait
{
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
