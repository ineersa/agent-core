<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Messenger;

use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/** Claim once, run the specialized worker, persist its returned outcome, notify owner. */
final readonly class ExecutionAuthorizationMiddleware implements MiddlewareInterface
{
    /** @param array<class-string, string> $requestTransports */
    public function __construct(
        private ExecutionOperationStoreInterface $operations,
        private MessageBusInterface $commandBus,
        private DeferredToolCompletionRepositoryInterface $deferredRepository,
        private array $requestTransports,
    ) {
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
            if (null !== $envelope->last(TransportNamesStamp::class)) {
                return $stack->next()->handle($envelope, $stack);
            }
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
            return $this->notify($envelope, $claim);
        }
        if (null === $claim) {
            return $envelope->with(new HandledStamp(null, self::class));
        }

        $resolved = $this->operations->resolveRequest($request, $authorization, $claim);
        $invocation = new Envelope($resolved, array_merge(...array_values($envelope->all())));
        try {
            $handled = $stack->next()->handle($invocation, $stack);
        } catch (\Throwable $exception) {
            throw new HandlerFailedException($envelope, [$exception]);
        }
        $result = null;
        foreach ($handled->all(HandledStamp::class) as $stamp) {
            $candidate = $stamp->getResult();
            if ($candidate instanceof AbstractAgentBusMessage && $candidate::class === ExecutionOperationMapper::resultType($resolved)) {
                $result = $candidate;
                break;
            }
        }
        if ($result instanceof AbstractAgentBusMessage) {
            $reference = $this->operations->saveResult($resolved, $authorization, $claim, $result);

            return $this->notify($envelope, $reference);
        }
        if ($resolved instanceof ExecuteToolCall) {
            $registration = $this->deferredRepository->findByRunAndToolCall($resolved->runId(), $resolved->toolCallId);
            if (null === $registration) {
                throw new \RuntimeException('Deferred execution has no durable registration.');
            }
            $this->operations->transferToDeferred($request, $authorization, $claim, $registration->deferredId);

            return $envelope->with(new HandledStamp(null, self::class));
        }
        $this->operations->resultForClaim($request, $authorization, $claim);

        return $handled;
    }

    private function notify(Envelope $envelope, DurableExecutionResult $result): Envelope
    {
        try {
            $this->commandBus->dispatch($result);
        } catch (\Throwable $exception) {
            // Retry the original small reference, never the decoded invocation.
            throw new HandlerFailedException($envelope, [$exception]);
        }

        return $envelope->with(new HandledStamp(null, self::class));
    }
}
