<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\AmpRetryClock;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\DTO\RuntimeConfigDTO;
use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

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
        /** @var AppConfig $appConfig */
        $appConfig = $container->get(AppConfig::class);
        $runtime = $appConfig->runtime;
        $this->assertSame(10, $runtime->llmConcurrency);
        $this->assertSame(RuntimeConfigDTO::DEFAULT_LLM_CONCURRENCY, $runtime->llmConcurrency);

        $platform = $container->get(LlmPlatformAdapter::class);
        $retryClock = (new \ReflectionProperty($platform, 'clock'))->getValue($platform);
        $this->assertInstanceOf(AmpRetryClock::class, $retryClock, 'LLM retries must yield instead of using the global NativeClock.');

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

    public function testExecuteUsesRuntimeConcurrencyForStrategy(): void
    {
        $concurrency = 6;
        $receiver = new class implements ReceiverInterface {
            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }
        };

        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return Envelope::wrap($message, $stamps);
            }
        };

        $receivers = new class($receiver) implements ContainerInterface {
            public function __construct(private ReceiverInterface $receiver)
            {
            }

            public function get(string $id): mixed
            {
                if ('llm' !== $id) {
                    throw new \InvalidArgumentException($id);
                }

                return $this->receiver;
            }

            public function has(string $id): bool
            {
                return 'llm' === $id;
            }
        };

        $buses = new class($bus) implements ServiceProviderInterface {
            public function __construct(private MessageBusInterface $bus)
            {
            }

            public function get(string $id): mixed
            {
                return $this->bus;
            }

            public function has(string $id): bool
            {
                return true;
            }

            public function getProvidedServices(): array
            {
                return ['default' => MessageBusInterface::class];
            }
        };

        $dispatcher = new EventDispatcher();
        $capturedLimit = null;
        $dispatcher->addListener(
            WorkerStartedEvent::class,
            static function (WorkerStartedEvent $event) use (&$capturedLimit): void {
                $worker = $event->getWorker();
                $strategyRef = new \ReflectionProperty($worker, 'messageExecutionStrategy');
                $strategy = $strategyRef->getValue($worker);
                self::assertInstanceOf(FiberMessageExecutionStrategy::class, $strategy);
                $limitRef = new \ReflectionProperty($strategy, 'concurrencyLimit');
                $capturedLimit = $limitRef->getValue($strategy);
                $worker->stop();
            },
        );

        $appConfig = clone self::getContainer()->get(AppConfig::class);
        $appConfig->runtime = new RuntimeConfigDTO(llmConcurrency: $concurrency);
        $command = new LlmFiberConsumeCommand(
            new RoutableMessageBus($buses, $bus),
            $receivers,
            $dispatcher,
            $appConfig,
        );

        $output = new BufferedOutput();
        $input = new ArrayInput([
            '--memory-limit' => '256M',
        ]);
        $input->bind($command->getDefinition());

        $status = $command->run($input, $output);

        $this->assertSame(0, $status);
        $this->assertSame($concurrency, $capturedLimit);
        $this->assertStringContainsString(
            \sprintf('Fiber concurrency %d', $concurrency),
            $output->fetch(),
        );
    }
}
