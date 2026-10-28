<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\ToolPayloadAdmission;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\EnvelopeAwareExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException as MessageValidationFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Seal before send_message; resolve only after owner initialization on consumption. */
final class ToolPayloadMiddleware implements MiddlewareInterface
{
    private bool $remoteResultOwner = false;

    public function __construct(
        private readonly ToolPayloadStoreInterface $payloads,
        private readonly ToolPayloadAdmission $admission,
        private readonly DeferredToolCompletionRepositoryInterface $deferred,
        private readonly RunLockManager $locks,
        private readonly ValidatorInterface $validator,
        private readonly MessageBusInterface $commandBus,
        private readonly LoggerInterface $logger,
        private readonly CompleteDeferredToolCallHandler $deferredHandler,
        private readonly DenormalizerInterface $serializer,
    ) {
    }

    public function useRemoteResultOwner(): void
    {
        $this->remoteResultOwner = true;
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
                if ('run_control' === $received->getTransportName() && null === $message->deferredId && 'result' === $message->reference->kind && 'code_mode' === $message->reference->toolName) {
                    $reference = $this->acceptCodeModeResult($message->reference);
                    if (null === $reference) {
                        return null;
                    }
                    $message = new ToolPayloadMessage($reference);
                }
                if ('result' === $message->reference->kind && 'code_mode' === $message->reference->toolName && $this->deferred->runRequiresContinuation($message->runId())) {
                    return null;
                }
                if (null !== $message->deferredId) {
                    $metadata = $this->deferredMetadata($message->deferredId);
                    $this->assertDeferredReference($metadata, $message->reference);
                    if ('completed' === $metadata->status || !$this->admission->acceptsDeferred($metadata->reference, 'run_control' === $received->getTransportName())) {
                        $ref = $metadata->reference;
                        $this->deferredHandler->settle($metadata->deferredId, $ref->runId, $ref->turnNo, $ref->stepId);

                        return null;
                    }
                } elseif (!$this->admission->accepts($message->reference, 'run_control' === $received->getTransportName())) {
                    return null;
                }
                try {
                    $body = $this->payloads->read($message->reference);
                } catch (\Throwable $exception) {
                    if ('call' !== $message->reference->kind && 'shell' !== $message->reference->kind) {
                        throw $exception;
                    }
                    // Resolution precedes the worker's exception boundary. Send
                    // a failed outcome instead of leaving its batch waiting.
                    $this->logger->warning('tool_payload.invocation_read_failed', [
                        'run_id' => $message->runId(), 'session_id' => $message->runId(),
                        'tool_call_id' => $message->reference->toolCallId, 'component' => 'tool_payload',
                        'event_type' => 'tool_payload.invocation_read_failed', 'exception_class' => $exception::class,
                    ]);

                    return $this->admission->failedInvocation($message->reference);
                }
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
                if ('run_control' === $received->getTransportName()) {
                    $this->admission->releaseTerminalChild($message->runId());
                }

                return $envelope->with(new HandledStamp(null, self::class));
            }
            if ($body instanceof ToolCallResult && 'result' !== $message->reference->kind) {
                // Use the normal result bus, after releasing the execution lock.
                try {
                    $this->commandBus->dispatch($body);
                } catch (\Throwable $exception) {
                    // Match tool workers: failed sends require explicit repair.
                    $this->logger->warning('runtime.result_send_failed', [
                        'run_id' => $message->runId(), 'session_id' => $message->runId(),
                        'tool_call_id' => $message->reference->toolCallId, 'component' => 'tool_payload',
                        'event_type' => 'runtime.result_send_failed', 'exception_class' => $exception::class,
                    ]);
                }

                return $envelope->with(new HandledStamp(null, self::class));
            }

            $hydrated = new Envelope($body, array_merge(...array_values($envelope->all())));
            if (null !== $ack = $envelope->last(AckStamp::class)) {
                // Batch handlers may acknowledge before returning to this bus.
                // The worker always tracks the original reference object.
                $hydrated = $hydrated->withoutAll(AckStamp::class)->with(new AckStamp(
                    fn (Envelope $handled, ?\Throwable $error = null) => $ack->ack(
                        $this->restoreReference($handled, $envelope),
                        null === $error ? null : $this->restoreException($error, $envelope),
                    ),
                ));
            }
            try {
                return $this->restoreReference($stack->next()->handle($hydrated, $stack), $envelope);
            } catch (EnvelopeAwareExceptionInterface $exception) {
                // Native retry listeners resend the exception's envelope directly.
                throw $this->restoreException($exception, $envelope);
            }
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
        if ($message instanceof CompleteDeferredToolCall) {
            $deferredId = $message->deferredId;
            $reference = $this->publishDeferred($message);
        } elseif ($message instanceof ToolCallResult) {
            [$deferredId, $reference] = $this->publishProcessedResult($message);
        } else {
            $deferredId = null;
            $reference = $this->locks->synchronized($message->runId(), fn () => $this->admission->canPublish($message) ? $this->payloads->publish($message) : null);
        }
        if (null === $reference) {
            return $envelope->with(new HandledStamp(null, self::class));
        }
        $transported = new ToolPayloadMessage($reference, $deferredId);
        $this->validate($transported);

        // Sending remains outside the run lock. Cleanup can race the send, but
        // the receiver checks existing admission before requiring the file.
        return $stack->next()->handle($this->route(new Envelope($transported, array_merge(...array_values($envelope->all()))), $transported), $stack);
    }

    private function publishDeferred(CompleteDeferredToolCall $message): ?ToolPayloadReferenceDTO
    {
        $initial = $this->deferredMetadata($message->deferredId);

        return $this->locks->synchronized($initial->reference->runId, function () use ($message, $initial): ?ToolPayloadReferenceDTO {
            $metadata = $this->deferredMetadata($message->deferredId);
            $this->assertDeferredReference($metadata, $initial->reference);
            if ('completed' === $metadata->status || !$this->admission->acceptsDeferred($metadata->reference, false)) {
                $ref = $metadata->reference;
                $this->deferredHandler->settle($metadata->deferredId, $ref->runId, $ref->turnNo, $ref->stepId);

                return null;
            }
            // Prefer the first accepted immutable result when present so failed
            // sends can redrive without rebuilding or re-executing the tool.
            if (null !== $metadata->acceptedResultReference) {
                return $metadata->acceptedResultReference;
            }
            $correlation = $this->deferred->findByDeferredId($message->deferredId);
            if (null === $correlation) {
                throw new ToolPayloadException('Active deferred completion has no registered correlation.');
            }
            $result = ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, $message->content, $message->details, $message->isError, $message->error);

            return $this->payloads->publish($result);
        });
    }

    /**
     * @return array{0: ?string, 1: ?ToolPayloadReferenceDTO}
     */
    private function publishProcessedResult(ToolCallResult $message): array
    {
        return $this->locks->synchronized($message->runId(), fn (): array => $this->payloads->withStorage($message->runId(), function () use ($message): array {
            if ('completed' === $this->deferred->statusForRunAndToolCall($message->runId(), $message->toolCallId)) {
                return [null, null];
            }
            $metadata = $this->findEarlyDeferredRegistration($message);
            if (null !== $metadata) {
                // Seal the first immutable result here, but leave SQL acceptance
                // to the owner receiver in tool workers. Owner-local repair and
                // serialized in-process execution use the same decision directly.
                $reference = $this->payloads->publishFirstResult($message, $metadata->reference);

                return [null, $this->remoteResultOwner ? $reference : $this->acceptCodeModeResult($reference, false)];
            }

            return [null, $this->admission->canPublish($message) ? $this->payloads->publish($message) : null];
        }));
    }

    private function acceptCodeModeResult(ToolPayloadReferenceDTO $incoming, bool $owner = true): ?ToolPayloadReferenceDTO
    {
        $metadata = $this->deferred->findMetadataByRunAndToolCall($incoming->runId, $incoming->toolCallId);
        if (null === $metadata) {
            return $incoming;
        }
        $this->assertDeferredReference($metadata, $incoming);
        if ('completed' === $metadata->status) {
            return null;
        }
        if (\in_array($metadata->interruptionReason, ['stopped', 'cancelled', 'history_discarded'], true) && (null !== $metadata->backgroundedAt || 'history_discarded' === $metadata->interruptionReason)) {
            $this->deferred->markCompleted($metadata->deferredId);

            return null;
        }
        if (null === $metadata->backgroundedAt && 'controller_lost' !== $metadata->interruptionReason && !$this->admission->accepts($incoming, $owner)) {
            return null;
        }
        $reference = $this->deferred->acceptResultReference($metadata->deferredId, $metadata->acceptedResultReference ?? $incoming);
        if ($metadata->continuationRequired) {
            return null;
        }
        if (null !== $metadata->backgroundedAt) {
            $accepted = $this->payloads->read($reference);
            if (!$accepted instanceof ToolCallResult) {
                throw new ToolPayloadException('Accepted deferred body is not a tool result.');
            }
            $notifications = ModelNotificationCodec::denormalizeFromDetails($this->serializer, $accepted->result['details'] ?? null);
            $delivery = new DeliverBackgroundCompletionDTO(
                $metadata->reference->runId,
                $metadata->deferredId,
                (new AgentMessageNormalizer())->toolMessage($accepted, $notifications)->content[0]['text'],
                $metadata->reference->runId,
                $metadata->deferredId,
                $metadata->reference,
            );
            $this->locks->afterRelease($incoming->runId, function () use ($delivery): void {
                $this->commandBus->dispatch($delivery);
            });

            return null;
        }

        return $reference;
    }

    private function findEarlyDeferredRegistration(ToolCallResult $message): ?DeferredToolCompletionMetadataDTO
    {
        $metadata = $this->deferred->findMetadataByRunAndToolCall($message->runId(), $message->toolCallId);
        if (null === $metadata || 'pending' !== $metadata->status || 'code_mode' !== $metadata->reference->toolName) {
            return null;
        }
        $stored = $metadata->reference;
        if ([$stored->runId, $stored->turnNo, $stored->stepId, $stored->attempt, $stored->idempotencyKey, $stored->toolCallId, $stored->orderIndex]
            !== [$message->runId(), $message->turnNo(), $message->stepId(), $message->attempt(), $message->idempotencyKey(), $message->toolCallId, $message->orderIndex]) {
            throw new ToolPayloadException('Early deferred registration does not match the processed tool result.');
        }

        return $metadata;
    }

    private function deferredMetadata(string $deferredId): DeferredToolCompletionMetadataDTO
    {
        return $this->deferred->findMetadataByDeferredId($deferredId)
            ?? throw new ToolPayloadException('Deferred completion has no registered correlation.');
    }

    private function assertDeferredReference(DeferredToolCompletionMetadataDTO $metadata, ToolPayloadReferenceDTO $reference): void
    {
        $stored = $metadata->reference;
        if ([$stored->runId, $stored->turnNo, $stored->stepId, $stored->attempt, $stored->idempotencyKey, $stored->toolCallId, $stored->orderIndex, $stored->toolName]
            !== [$reference->runId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey, $reference->toolCallId, $reference->orderIndex, $reference->toolName]) {
            throw new ToolPayloadException('Deferred completion reference has a different correlation.');
        }
    }

    private function restoreReference(Envelope $handled, Envelope $original): Envelope
    {
        $restored = new Envelope($original->getMessage(), array_merge(...array_values($handled->all())));
        if (null !== $ack = $original->last(AckStamp::class)) {
            $restored = $restored->withoutAll(AckStamp::class)->with($ack);
        }

        return $restored;
    }

    private function restoreException(\Throwable $exception, Envelope $original): \Throwable
    {
        if ($exception instanceof HandlerFailedException) {
            return new HandlerFailedException(
                $this->restoreReference($exception->getEnvelope(), $original),
                $exception->getWrappedExceptions(),
            );
        }
        if ($exception instanceof DelayedMessageHandlingException && null !== $exception->getEnvelope()) {
            return new DelayedMessageHandlingException(
                $exception->getWrappedExceptions(),
                $this->restoreReference($exception->getEnvelope(), $original),
            );
        }
        if ($exception instanceof MessageValidationFailedException && null !== $exception->getEnvelope()) {
            return new MessageValidationFailedException(
                $exception->getViolatingMessage(), $exception->getViolations(),
                $this->restoreReference($exception->getEnvelope(), $original),
            );
        }
        if ($exception instanceof EnvelopeAwareExceptionInterface && null !== $exception->getEnvelope()) {
            // Symfony inspects wrapped recoverable/unrecoverable causes and their
            // retry delays. Keep an unknown implementation intact as that cause.
            return new HandlerFailedException($this->restoreReference($exception->getEnvelope(), $original), [$exception]);
        }

        return $exception;
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
