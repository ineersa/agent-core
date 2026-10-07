<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/** Unwraps durable result references only at owner consumption. */
final readonly class ExecutionResultMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ExecutionOperationStoreInterface $operations,
        private PendingTransitionRecovery $recovery,
        private RunLockManager $locks,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if (!$message instanceof DurableExecutionResult || null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }
        $this->locks->synchronized($message->runId(), function () use ($message): void {
            $this->recovery->recover($message->runId());
        });
        if ($this->operations->isDisposed($message)) {
            return $envelope->with(new HandledStamp(null, self::class));
        }
        $result = $this->operations->resolveResult($message);

        $stamps = array_merge(...array_values($envelope->all()));
        $stamps[] = new HandlerArgumentsStamp([$message]);

        return $stack->next()->handle(new Envelope($result, $stamps), $stack);
    }
}
