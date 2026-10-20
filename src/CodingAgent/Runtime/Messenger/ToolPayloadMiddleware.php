<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\ToolPayloadAdmission;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Seal before send_message; resolve only after owner initialization on consumption. */
final readonly class ToolPayloadMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ToolPayloadStoreInterface $payloads,
        private ToolPayloadAdmission $admission,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private RunLockManager $locks,
        private ValidatorInterface $validator,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $received = $envelope->last(ReceivedStamp::class);
        if (null !== $received) {
            if (!$message instanceof ToolPayloadMessage) {
                // Inline tool payloads are not a supported transport format.
                if ($message instanceof ExecuteToolCall || $message instanceof ExecuteShellToolCall || $message instanceof ToolCallResult || $message instanceof CompleteDeferredToolCall) {
                    throw new ToolPayloadException('Received an inline tool payload instead of a data reference.');
                }

                return $stack->next()->handle($envelope, $stack);
            }
            $this->validate($message);
            $body = $this->locks->synchronized($message->runId(), function () use ($message, $received): ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|CompleteDeferredToolCall|null {
                if (null !== $message->deferredId) {
                    if ('pending' !== $this->deferred->status($message->deferredId)) {
                        return null;
                    }
                    $correlation = $this->deferred->findByDeferredId($message->deferredId);
                    $ref = $message->reference;
                    if (null === $correlation || $correlation->runId !== $ref->runId || $correlation->turnNo !== $ref->turnNo || $correlation->stepId !== $ref->stepId || $correlation->toolCallId !== $ref->toolCallId || $correlation->attempt !== $ref->attempt || $correlation->idempotencyKey !== $ref->idempotencyKey || 'result' !== $ref->kind) {
                        throw new ToolPayloadException('Deferred completion reference has a different correlation.');
                    }
                }
                if (!$this->admission->accepts($message->reference, 'run_control' === $received->getTransportName())) {
                    return null;
                }
                $body = $this->payloads->read($message->reference);
                if (!$body instanceof ExecuteToolCall && !$body instanceof ExecuteShellToolCall && !$body instanceof ToolCallResult) {
                    throw new ToolPayloadException('Transport reference does not identify tool invocation or result data.');
                }
                if (null !== $message->deferredId) {
                    if (!$body instanceof ToolCallResult || !\is_array($body->result)) {
                        throw new ToolPayloadException('Deferred completion data is not a tool result.');
                    }

                    return new CompleteDeferredToolCall($message->deferredId, $body->result['content'], $body->result['details'] ?? null, $body->isError, $body->error);
                }

                return $body;
            });
            if (null === $body) {
                return $envelope->with(new HandledStamp(null, self::class));
            }

            return $stack->next()->handle(new Envelope($body, array_merge(...array_values($envelope->all()))), $stack);
        }
        if ($message instanceof ToolPayloadMessage) {
            // Repair/redelivery may already hold a sealed reference. Do not
            // hydrate it on the producer or create a new delivery identity.
            $this->validate($message);

            return $stack->next()->handle($this->route($envelope, $message), $stack);
        }
        if (!$message instanceof ExecuteToolCall && !$message instanceof ExecuteShellToolCall && !$message instanceof ToolCallResult && !$message instanceof CompleteDeferredToolCall) {
            return $stack->next()->handle($envelope, $stack);
        }
        $deferredId = null;
        if ($message instanceof CompleteDeferredToolCall) {
            $deferredId = $message->deferredId;
            if ('completed' === $this->deferred->status($deferredId)) {
                return $envelope->with(new HandledStamp(null, self::class));
            }
            $correlation = $this->deferred->findByDeferredId($deferredId);
            if (null === $correlation) {
                throw new ToolPayloadException('Deferred completion has no registered correlation.');
            }
            // Store the final result data once. The deferred handler's subsequent
            // ordinary result dispatch and SQL collection reuse this same digest.
            $message = ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, $message->content, $message->details, $message->isError, $message->error);
        }
        $reference = $this->locks->synchronized($message->runId(), fn () => $this->admission->canPublish($message) ? $this->payloads->publish($message) : null);
        if (null === $reference) {
            return $envelope->with(new HandledStamp(null, self::class));
        }
        $transported = new ToolPayloadMessage($reference, $deferredId);
        $this->validate($transported);
        // Sending remains outside the run lock. Cleanup can race the send, but
        // the receiver checks existing admission before requiring the file.
        return $stack->next()->handle($this->route(new Envelope($transported, array_merge(...array_values($envelope->all()))), $transported), $stack);
    }

    private function route(Envelope $envelope, ToolPayloadMessage $message): Envelope
    {
        if (null !== $envelope->last(TransportNamesStamp::class)) {
            return $envelope;
        }

        return $envelope->with(new TransportNamesStamp(['result' === $message->reference->kind ? 'run_control' : 'tool']));
    }

    private function validate(ToolPayloadMessage $message): void
    {
        $errors = $this->validator->validate($message);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($message, $errors);
        }
        if ('correlation' === $message->reference->kind || (null !== $message->deferredId && 'result' !== $message->reference->kind)) {
            throw new ToolPayloadException('Tool transport reference has an unsupported data kind.');
        }
    }
}
