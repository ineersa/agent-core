<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
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
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionFactory;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Message\DTO\SettleObsoleteDeferredCompletionDTO;
use Ineersa\CodingAgent\Application\Pipeline\CodeModeLifecycleService;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\ToolPayloadAdmission;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashNativeCompletionMetadataDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
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
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Exception\ValidationFailedException as ValidatorValidationFailedException;
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
        private readonly DenormalizerInterface $serializer,
        private readonly CodeModeLifecycleService $codeModeLifecycle,
        private readonly AgentResumeExecutionService $agentResume,
        private readonly DeferredSubagentBatchLaunchService $deferredChildLaunch,
        private readonly SubmissionFactory $submissionFactory,
        private readonly BackgroundProcessManager $backgroundProcesses,
        private readonly ProcessStore $processStore,
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
            $nativeStamp = $envelope->last(BashNativeCompletionStamp::class);
            $body = $this->locks->synchronized($message->runId(), function () use ($message, $received, $nativeStamp): ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|CompleteDeferredToolCall|SettleObsoleteDeferredCompletionDTO|null {
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
                if ('run_control' === $received->getTransportName() && $nativeStamp instanceof BashNativeCompletionStamp) {
                    $this->applyBashNativeCompletionStamp(
                        $message->reference,
                        $nativeStamp,
                        $this->admission->accepts($message->reference, true),
                    );
                }
                if (null !== $message->deferredId) {
                    $metadata = $this->deferredMetadata($message->deferredId);
                    $this->assertDeferredReference($metadata, $message->reference);
                    if ('completed' === $metadata->status) {
                        return null;
                    }
                    if (!$this->admission->acceptsDeferred($metadata->reference, 'run_control' === $received->getTransportName())) {
                        if ('run_control' === $received->getTransportName()) {
                            // Owner receive of an obsolete deferred body settles metadata only.
                            return new SettleObsoleteDeferredCompletionDTO(
                                $metadata->reference->runId,
                                $metadata->reference->turnNo,
                                $metadata->reference->stepId,
                                $metadata->deferredId,
                            );
                        }
                        $this->commandBus->dispatch(new SettleObsoleteDeferredCompletionDTO(
                            $metadata->reference->runId,
                            $metadata->reference->turnNo,
                            $metadata->reference->stepId,
                            $metadata->deferredId,
                        ));

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

                    return new CompleteDeferredToolCall(
                        $message->deferredId,
                        $body->result['content'],
                        $body->result['details'] ?? null,
                        $body->isError,
                        $body->error,
                    );
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
        $nativeStamp = null;
        if ($message instanceof CompleteDeferredToolCall) {
            $deferredId = $message->deferredId;
            $reference = $this->publishDeferred($message);
        } elseif ($message instanceof ToolCallResult) {
            [$deferredId, $reference, $nativeStamp] = $this->publishProcessedResult($message);
        } else {
            $deferredId = null;
            $reference = $this->locks->synchronized($message->runId(), function () use ($message, &$deferredId): ?ToolPayloadReferenceDTO {
                if (!$this->admission->canPublish($message)) {
                    return null;
                }
                // Prepare code_mode deferred identity before the execution
                // reference is sealed and sent. Failure prevents dispatch.
                if ($message instanceof ExecuteToolCall && 'code_mode' === $message->toolName) {
                    $this->codeModeLifecycle->prepare($message);
                }
                if ($message instanceof ExecuteToolCall) {
                    try {
                        $this->prepareDeferredExecution($message);
                    } catch (ToolCallException $exception) {
                        // Preparation refusals settle as ordinary failed results.
                        // Do not dispatch execution or throw into owner effect retries.
                        $failed = ToolCallResultFactory::fromExecuteToolCallAndThrowable($message, $exception);
                        $deferredId = null;

                        return $this->payloads->publish($failed);
                    }
                }
                if ($message instanceof ExecuteShellToolCall) {
                    $this->prepareDirectShellNative($message);
                }

                return $this->payloads->publish($message);
            });
        }
        if (null === $reference) {
            return $envelope->with(new HandledStamp(null, self::class));
        }
        $transported = new ToolPayloadMessage($reference, $deferredId);
        $this->validate($transported);

        // Sending remains outside the run lock. Cleanup can race the send, but
        // the receiver checks existing admission before requiring the file.
        $stamps = array_merge(...array_values($envelope->all()));
        if ($nativeStamp instanceof BashNativeCompletionStamp) {
            $stamps[] = $nativeStamp;
        }

        return $stack->next()->handle($this->route(new Envelope($transported, $stamps), $transported), $stack);
    }

    private function publishDeferred(CompleteDeferredToolCall $message): ?ToolPayloadReferenceDTO
    {
        $initial = $this->deferredMetadata($message->deferredId);

        return $this->locks->synchronized($initial->reference->runId, function () use ($message, $initial): ?ToolPayloadReferenceDTO {
            $metadata = $this->deferredMetadata($message->deferredId);
            $this->assertDeferredReference($metadata, $initial->reference);
            if ('completed' === $metadata->status) {
                // Already settled duplicate: no new conversational result.
                return null;
            }
            if (!$this->admission->acceptsDeferred($metadata->reference, false)) {
                // Forward obsolete pending completions to the owner as metadata-only settlement.
                $this->commandBus->dispatch(new SettleObsoleteDeferredCompletionDTO(
                    $metadata->reference->runId,
                    $metadata->reference->turnNo,
                    $metadata->reference->stepId,
                    $metadata->deferredId,
                ));

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
     * @return array{0: ?string, 1: ?ToolPayloadReferenceDTO, 2: ?BashNativeCompletionStamp}
     */
    private function publishProcessedResult(ToolCallResult $message): array
    {
        return $this->locks->synchronized($message->runId(), fn (): array => $this->payloads->withStorage($message->runId(), function () use ($message): array {
            $details = \is_array($message->result) ? ($message->result['details'] ?? null) : null;
            $nativeStamp = $this->extractBashNativeCompletionStamp($message, $details);
            if ($nativeStamp instanceof BashNativeCompletionStamp) {
                $visible = $message;
                if (\is_array($message->result)) {
                    $visibleDetails = \is_array($details) ? $details : [];
                    unset($visibleDetails['bash_native']);
                    $visible = new ToolCallResult(
                        $message->runId(),
                        $message->turnNo(),
                        $message->stepId(),
                        $message->attempt(),
                        $message->idempotencyKey(),
                        $message->toolCallId,
                        $message->orderIndex,
                        [
                            ...$message->result,
                            'details' => [] === $visibleDetails ? null : $visibleDetails,
                        ],
                        $message->isError,
                        $message->error,
                    );
                }
                // Seal ordinary visible content; native facts travel on the stamp.
                // Publication must still reach owner after caller staleness.
                $reference = $this->payloads->publish($visible);

                return [null, $reference, $nativeStamp];
            }
            $metadata = $this->deferred->findMetadataByRunAndToolCall($message->runId(), $message->toolCallId);
            $status = null !== $metadata
                ? $metadata->status
                : $this->deferred->statusForRunAndToolCall($message->runId(), $message->toolCallId);
            if ('completed' === $status) {
                return [null, null, null];
            }
            $metadata = $this->findEarlyDeferredRegistration($message);
            if (null !== $metadata) {
                // Seal the first immutable result here, but leave SQL acceptance
                // to the owner receiver in tool workers. Owner-local repair and
                // serialized in-process execution use the same decision directly.
                $reference = $this->payloads->publishFirstResult($message, $metadata->reference);

                return [null, $this->remoteResultOwner ? $reference : $this->acceptCodeModeResult($reference, false), null];
            }

            if (!$this->admission->canPublish($message)) {
                return [null, null, null];
            }

            return [null, $this->payloads->publish($message), null];
        }));
    }

    /**
     * Owner-side reservation and deferred correlation preparation before dispatch.
     *
     * Child tools reuse the reserved lifecycle id. Communication tools freeze the
     * submission identity. Readiness stays unset until launch success or host ready.
     */
    private function prepareDeferredExecution(ExecuteToolCall $message): void
    {
        if ('agent_resume' === $message->toolName) {
            $projection = $this->agentResume->prepare($message);
            $this->deferred->preparePending(ToolCallResultFactory::correlationFromExecuteToolCall($message, $projection->lifecycleId));

            return;
        }

        if (\in_array($message->toolName, ['subagent', 'fork'], true)) {
            $projection = $this->deferredChildLaunch->prepare($message);
            $this->deferred->preparePending(ToolCallResultFactory::correlationFromExecuteToolCall($message, $projection->lifecycleId));

            return;
        }

        if ('bash' === $message->toolName) {
            $this->prepareModelBashNative($message);

            return;
        }

        if (\in_array($message->toolName, ['agent_steer', 'notify_parent'], true)) {
            try {
                $receipt = $this->submissionFactory->prepare(
                    $message->runId(),
                    $message->turnNo(),
                    $message->stepId(),
                    $message->toolCallId,
                    $message->toolName,
                    $message->args,
                );
            } catch (ValidatorValidationFailedException $exception) {
                throw new ToolCallException($exception->getMessage(), retryable: false, previous: $exception, hint: 'preparation_unused');
            }
            $this->deferred->preparePending(ToolCallResultFactory::correlationFromExecuteToolCall($message, $receipt->identity));
        }
    }

    /**
     * Extract and validate private bash_native facts for envelope stamp transport.
     *
     * @param array<string, mixed>|null $details
     */
    private function extractBashNativeCompletionStamp(ToolCallResult $message, ?array $details): ?BashNativeCompletionStamp
    {
        if (!\is_array($details)) {
            return null;
        }
        $native = $details['bash_native'] ?? null;
        if (null === $native) {
            return null;
        }
        try {
            $metadata = $this->serializer->denormalize(
                $native,
                BashNativeCompletionMetadataDTO::class,
                context: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (\Throwable $exception) {
            throw new ToolPayloadException('Bash native completion metadata is invalid.', previous: $exception);
        }
        if (!$metadata instanceof BashNativeCompletionMetadataDTO) {
            throw new ToolPayloadException('Bash native completion metadata is invalid.');
        }
        $violations = $this->validator->validate($metadata);
        if (\count($violations) > 0) {
            throw new ToolPayloadException((string) $violations);
        }
        if ($metadata->runId !== $message->runId() || $metadata->toolCallId !== $message->toolCallId) {
            throw new ToolPayloadException('Bash native completion does not match the sealed result identity.');
        }

        return new BashNativeCompletionStamp($metadata);
    }

    /**
     * Owner applies private native Bash facts from the envelope stamp before
     * ordinary admission. Missing prepared rows after cleanup are no-ops for
     * stale envelopes; active results still require matching preparation.
     */
    private function applyBashNativeCompletionStamp(
        ToolPayloadReferenceDTO $reference,
        BashNativeCompletionStamp $stamp,
        bool $activeCaller,
    ): void {
        $metadata = $stamp->metadata;
        if ('result' !== $reference->kind || 'bash' !== $reference->toolName) {
            throw new ToolPayloadException('Bash native completion stamp requires an ordinary bash result reference.');
        }
        $violations = $this->validator->validate($metadata);
        if (\count($violations) > 0) {
            throw new ToolPayloadException((string) $violations);
        }
        if ($metadata->runId !== $reference->runId || $metadata->toolCallId !== $reference->toolCallId) {
            throw new ToolPayloadException('Bash native completion does not match the transport reference identity.');
        }
        $prepared = $this->backgroundProcesses->findPreparedLaunch($metadata->runId, $metadata->toolCallId);
        if (null === $prepared) {
            if (!$activeCaller) {
                // Stale envelope after process-row cleanup: preserve no-op.
                return;
            }

            throw new ToolPayloadException('Bash native completion has no prepared native row.');
        }
        if ($prepared->id !== $metadata->recordId) {
            throw new ToolPayloadException('Bash native completion does not match its prepared native row.');
        }
        if (null !== $metadata->pid) {
            if (null === $metadata->startedAt) {
                throw new ToolPayloadException('Bash launch acknowledgment requires the actual started_at.');
            }
            $this->processStore->applyLaunch(
                $metadata->recordId,
                $metadata->runId,
                $metadata->pid,
                $metadata->pgid,
                $metadata->startedAt,
                $metadata->deadlineNanoseconds,
            );
        }
        if (null !== $metadata->acceptedAt) {
            $this->processStore->applyAcceptedByRecord($metadata->recordId, $metadata->runId, $metadata->acceptedAt);
        }
        if (null !== $metadata->finishedAt) {
            if (null === $metadata->status) {
                throw new ToolPayloadException('Bash finish acknowledgment requires a native status.');
            }
            $this->processStore->applyFinishedByRecord(
                $metadata->recordId,
                $metadata->runId,
                $metadata->exitCode,
                $metadata->status,
                $metadata->stoppedByUser,
                $metadata->finishedAt,
            );
        }
    }

    private function prepareModelBashNative(ExecuteToolCall $message): void
    {
        try {
            $arguments = $this->serializer->denormalize(
                $message->args,
                BashArgumentsDTO::class,
                context: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (\Throwable $exception) {
            throw new ToolCallException('Bash arguments are invalid.', retryable: false, previous: $exception, hint: 'preparation_unused');
        }
        if (!$arguments instanceof BashArgumentsDTO) {
            throw new ToolCallException('Bash arguments are invalid.', retryable: false, hint: 'preparation_unused');
        }
        $violations = $this->validator->validate($arguments);
        if (\count($violations) > 0) {
            throw new ToolCallException((string) $violations, retryable: false, hint: 'preparation_unused');
        }

        $this->backgroundProcesses->prepareNativeLaunch(
            $message->runId(),
            $message->toolCallId,
            $arguments->command,
            $arguments->backgrounded,
        );
    }

    private function prepareDirectShellNative(ExecuteShellToolCall $message): void
    {
        $this->backgroundProcesses->prepareNativeLaunch(
            $message->runId(),
            $message->toolCallId,
            $message->commandText,
            false,
        );
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
        // Preparation refusals convert an agent/tool call stamp into a result.
        // Result references always own run_control, even when a prior stamp remains.
        if ('result' === $message->reference->kind) {
            return $envelope->withoutAll(TransportNamesStamp::class)
                ->with(new TransportNamesStamp(['run_control']));
        }
        if (null !== $envelope->last(TransportNamesStamp::class)) {
            return $envelope;
        }

        return $envelope->with(new TransportNamesStamp(['tool']));
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
