<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandlerLogComponentInterface;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\CodingAgent\Application\Pipeline\CompactionStepResultHandler;
use Ineersa\CodingAgent\Application\Pipeline\CompactRunHandler;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * The processor enters handler-provided components around state transitions.
 * This production-container test keeps the handler capability contract explicit
 * without relying on the removed pre-handler replay/logging path.
 */
final class RunMessageProcessorLogComponentTest extends IsolatedKernelTestCase
{
    public function testRegisteredLaneHandlersExposeDedicatedComponents(): void
    {
        $this->assertSame('compaction', $this->componentFor(CompactRunHandler::class));
        $this->assertSame('compaction', $this->componentFor(CompactionStepResultHandler::class));
        $this->assertSame('llm', $this->componentFor(LlmStepResultHandler::class));
        $this->assertSame('tool', $this->componentFor(ToolCallResultHandler::class));
    }

    public function testRegisteredCoreHandlerUsesProcessorRuntimeFallback(): void
    {
        $handler = $this->handlerFor(AdvanceRunHandler::class);

        $this->assertNotInstanceOf(RunMessageHandlerLogComponentInterface::class, $handler);
    }

    private function componentFor(string $class): string
    {
        $handler = $this->handlerFor($class);
        $this->assertInstanceOf(RunMessageHandlerLogComponentInterface::class, $handler);

        return $handler->getLogComponent();
    }

    private function handlerFor(string $class): RunMessageHandler
    {
        $processor = static::getContainer()->get(RunMessageProcessor::class);
        $property = new \ReflectionProperty(RunMessageProcessor::class, 'handlers');
        /** @var list<RunMessageHandler> $handlers */
        $handlers = $property->getValue($processor);

        foreach ($handlers as $handler) {
            if ($handler::class === $class) {
                return $handler;
            }
        }

        throw new \LogicException(\sprintf('Production RunMessageHandler registration does not contain %s.', $class));
    }
}
