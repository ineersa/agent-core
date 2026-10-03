<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Thesis: the shared AdvanceRun post-commit callback owner dispatches with
 * the canonical step-id/idempotency-key mechanics and wraps Messenger
 * failures into the flow-specific RuntimeException.
 */
final class AdvanceRunCoordinationFactoryTest extends TestCase
{
    public function testCreateDispatchesAdvanceRunWithCanonicalKeyAndAttempt(): void
    {
        $commandBus = new TestMessageBus();

        $callback = AdvanceRunCoordinationFactory::create(
            'run-advance-1',
            7,
            'follow-up',
            'Failed to dispatch follow-up AdvanceRun command.',
        );
        \Ineersa\AgentCore\Tests\Support\CoordinationActionTestRunner::run($callback, $commandBus);

        $this->assertCount(1, $commandBus->messages);
        $advance = $commandBus->messages[0];
        $this->assertInstanceOf(AdvanceRun::class, $advance);
        $this->assertSame('run-advance-1', $advance->runId());
        $this->assertSame(7, $advance->turnNo());
        $this->assertSame(1, $advance->attempt());
        $this->assertStringStartsWith('follow-up-', $advance->stepId());
        $this->assertSame(
            hash('sha256', \sprintf('%s|%s', $advance->runId(), $advance->stepId())),
            $advance->idempotencyKey(),
        );
    }

    public function testRepeatedDispatchPreservesPreparedIdentity(): void
    {
        $commandBus = new TestMessageBus();
        $callback = AdvanceRunCoordinationFactory::create('run-advance-2', 3, 'post-cancel-advance', 'err');

        \Ineersa\AgentCore\Tests\Support\CoordinationActionTestRunner::run($callback, $commandBus);
        \Ineersa\AgentCore\Tests\Support\CoordinationActionTestRunner::run($callback, $commandBus);

        $this->assertCount(2, $commandBus->messages);
        $this->assertSame($commandBus->messages[0]->stepId(), $commandBus->messages[1]->stepId());
        $this->assertStringStartsWith('post-cancel-advance-', $commandBus->messages[0]->stepId());
    }

    public function testCreateWrapsMessengerFailuresInRuntimeExceptionWithMessageAndPrevious(): void
    {
        $throwingBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \Symfony\Component\Messenger\Exception\RuntimeException('bus exploded');
            }
        };

        $callback = AdvanceRunCoordinationFactory::create(
            'run-advance-3',
            2,
            'advance-after-tools',
            'Failed to dispatch AdvanceRun after tool batch completion.',
        );

        try {
            \Ineersa\AgentCore\Tests\Support\CoordinationActionTestRunner::run($callback, $throwingBus);
            $this->fail('Expected RuntimeException from the callback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failed to dispatch AdvanceRun after tool batch completion.', $exception->getMessage());
            $this->assertInstanceOf(ExceptionInterface::class, $exception->getPrevious());
            $this->assertSame('bus exploded', $exception->getPrevious()?->getMessage());
        }
    }
}
