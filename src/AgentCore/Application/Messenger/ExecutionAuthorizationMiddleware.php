<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Messenger;

use Ineersa\AgentCore\Application\Handler\ExecutionBoundaryContext;
use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final readonly class ExecutionAuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(private ExecutionOperationStoreInterface $operations, private ExecutionBoundaryContext $context, private MessageBusInterface $commandBus)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $request = $envelope->getMessage();
        if (!$request instanceof AbstractAgentBusMessage || !ExecutionOperationMapper::supports($request) || null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }
        $authorization = $envelope->last(ExecutionAuthorizationStamp::class);
        if (!$authorization instanceof ExecutionAuthorizationStamp) {
            throw new \RuntimeException('Execution delivery has no owner authorization identity.');
        }
        $claim = $this->operations->claim($request, $authorization);
        if ($claim instanceof DurableExecutionResult) {
            $this->commandBus->dispatch($claim);

            return $envelope->with(new HandledStamp(null, self::class));
        }
        if (null === $claim) {
            // Running and disposed work is never executed again on redelivery.
            return $envelope->with(new HandledStamp(null, self::class));
        }

        return $this->context->executing($request, $authorization, $claim, static fn (): Envelope => $stack->next()->handle($envelope, $stack));
    }
}
