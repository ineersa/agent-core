<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Config\RuntimeConfig;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber
 */
final class LlmFiberConsumeCommandTest extends IsolatedKernelTestCase
{
    public function testCompiledContainerRegistersFiberLlmConsumeCommandAndWaitSubscriber(): void
    {
        $container = self::getContainer();

        /** @var LlmFiberConsumeCommand $command */
        $command = $container->get(LlmFiberConsumeCommand::class);
        /** @var RuntimeConfig $runtime */
        $runtime = $container->get(RuntimeConfig::class);
        $this->assertSame(10, $runtime->llmConcurrency);
        $this->assertSame(RuntimeConfig::DEFAULT_LLM_CONCURRENCY, $runtime->llmConcurrency);

        $attribute = (new \ReflectionClass($command))->getAttributes(AsCommand::class)[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertSame('hatfield:messenger:consume-llm', $attribute->newInstance()->name);

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('memory-limit'));
        $this->assertTrue($definition->hasOption('keepalive'));
        $this->assertFalse($definition->hasOption('sleep'));
        $this->assertFalse($definition->hasOption('bus'));
        $this->assertFalse($definition->hasOption('concurrency'));

        if (\Symfony\Component\Console\SignalRegistry\SignalRegistry::isSupported()) {
            $signals = $command->getSubscribedSignals();
            $this->assertContains(\SIGTERM, $signals);
            $this->assertContains(\SIGINT, $signals);
            $this->assertContains(\SIGQUIT, $signals);
            $this->assertNotContains(
                \SIGALRM,
                $signals,
                'Amp/UvDriver defers pcntl_alarm; Fiber llm keepalive must use Revolt EventLoop::repeat.',
            );
        }

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        $found = false;
        foreach ($dispatcher->getListeners() as $eventListeners) {
            foreach ($eventListeners as $listener) {
                if (\is_array($listener) && ($listener[0] ?? null) instanceof LlmFiberConsumeWaitSubscriber) {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($found, 'LlmFiberConsumeWaitSubscriber must be registered on the event dispatcher.');
    }
}
