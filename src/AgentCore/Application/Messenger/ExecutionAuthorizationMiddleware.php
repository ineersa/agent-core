<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Messenger;

use Ineersa\AgentCore\Application\Handler\ExecutionBoundaryContext;
use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class ExecutionAuthorizationMiddleware implements MiddlewareInterface
{
    /** @param array<class-string, string> $requestTransports */
    public function __construct(private ExecutionOperationStoreInterface $operations, private ExecutionBoundaryContext $context, private MessageBusInterface $commandBus, private array $requestTransports)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $request = $envelope->getMessage();
        if (!$request instanceof ExecutionRequest) {
            if ($request instanceof AbstractAgentBusMessage && ExecutionOperationMapper::supports($request)) {
                throw new \RuntimeException('Execution delivery requires an immutable input reference.');
            }

            return $stack->next()->handle($envelope, $stack);
        }
        if (null === $envelope->last(ReceivedStamp::class)) {
            $transport = $this->requestTransports[$request->requestType] ?? null;
            if (null === $transport) {
                throw new \RuntimeException('Execution reference has no configured transport.');
            }

            return $stack->next()->handle($envelope->with(new TransportNamesStamp([$transport])), $stack);
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

        $resolved = $this->operations->resolveRequest($request, $authorization, $claim);
        $invocation = new Envelope($resolved, array_merge(...array_values($envelope->all())));
        try {
            $handled = $this->context->executing($resolved, $authorization, $claim, static fn (): Envelope => $stack->next()->handle($invocation, $stack));
        } catch (\Throwable $exception) {
            // Messenger retries the exception's envelope. Never let a resolved
            // invocation replace its small immutable delivery reference.
            throw new HandlerFailedException($envelope, [$exception]);
        }
        $this->operations->resultForClaim($request, $authorization, $claim);

        return $handled;
    }
}
