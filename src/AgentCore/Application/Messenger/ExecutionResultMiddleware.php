<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Messenger;

use Ineersa\AgentCore\Application\Handler\ExecutionBoundaryContext;
use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/** Seals worker results before notification; unwraps them only at owner consumption. */
final readonly class ExecutionResultMiddleware implements MiddlewareInterface
{
    public function __construct(private ExecutionOperationStoreInterface $operations, private ExecutionBoundaryContext $context)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if ($message instanceof DurableExecutionResult && null !== $envelope->last(ReceivedStamp::class)) {
            $result = $this->operations->resolveResult($message);

            return $this->context->accepting($message, static fn (): Envelope => $stack->next()->handle(new Envelope($result, array_merge(...array_values($envelope->all()))), $stack));
        }
        $execution = $this->context->currentExecution();
        $expected = null !== $execution ? ExecutionOperationMapper::resultType($execution[0]) : null;
        if (null !== $execution && null !== $expected && $message instanceof AbstractAgentBusMessage && $message instanceof $expected) {
            $reference = $this->operations->saveResult($execution[0], $execution[1], $execution[2], $message);

            return $stack->next()->handle(new Envelope($reference, array_merge(...array_values($envelope->all()))), $stack);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
