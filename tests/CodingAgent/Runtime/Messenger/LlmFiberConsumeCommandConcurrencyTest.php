<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\CodingAgent\Config\RuntimeConfig;
use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Thesis: hatfield:messenger:consume-llm admits Fiber capacity from
 * runtime.llm_concurrency, not a hardcoded constant.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand
 */
#[CoversClass(LlmFiberConsumeCommand::class)]
final class LlmFiberConsumeCommandConcurrencyTest extends TestCase
{
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

        $command = new LlmFiberConsumeCommand(
            new RoutableMessageBus($buses, $bus),
            $receivers,
            $dispatcher,
            new RuntimeConfig(llmConcurrency: $concurrency),
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
