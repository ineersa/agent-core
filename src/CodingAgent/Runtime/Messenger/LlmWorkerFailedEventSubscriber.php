<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RetryableLlmStepFailureException;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After final llm transport failure for {@see ExecuteLlmStep}, dispatch exactly
 * one sanitized non-retryable {@see LlmStepResult} to run_control. Exhausted
 * provider failures retain approved structural diagnostics; other failures use
 * a generic delivery error so every final worker path terminalizes the run.
 *
 * Intermediate retries dispatch nothing. Canonical mutation remains owned by
 * run_control handlers; this subscriber only bridges final transport failure.
 */
final readonly class LlmWorkerFailedEventSubscriber implements EventSubscriberInterface
{
    private const string RECEIVER_NAME = 'llm';

    /** @var list<string> */
    private const array PRIVACY_SAFE_ERROR_KEYS = [
        'type',
        'message',
        'retryable',
        'error_category',
        'user_message',
        'http_status_code',
        'response_error_code',
        'response_error_type',
        'response_error_param',
        'request_model',
        'request_reasoning',
        'request_tools_enabled',
        'request_message_count',
        'request_has_tools',
    ];

    public function __construct(
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        if (self::RECEIVER_NAME !== $event->getReceiverName()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ExecuteLlmStep) {
            return;
        }

        $providerFailure = $this->findProviderFailure($event->getThrowable());
        $error = null === $providerFailure
            ? $this->genericTerminalError()
            : $this->sanitizeTerminalError($providerFailure->error);

        $result = new LlmStepResult(
            runId: $message->runId(),
            turnNo: $message->turnNo(),
            stepId: $message->stepId(),
            attempt: $message->attempt(),
            idempotencyKey: $message->idempotencyKey(),
            assistantMessage: null,
            usage: [],
            stopReason: 'error',
            error: $error,
            toolsRef: $providerFailure->toolsRef ?? $message->toolsRef,
            model: $providerFailure->model ?? '',
            reasoning: $providerFailure->reasoning ?? '',
            modelNotifications: $providerFailure->modelNotifications ?? [],
            availableTools: $providerFailure->availableTools ?? [],
            availableToolsSchemaTokensEstimate: $providerFailure->availableToolsSchemaTokensEstimate ?? 0,
        );

        try {
            $this->commandBus->dispatch($result);
            $this->logger->info('llm.worker_failed.terminal_result_dispatched', [
                'run_id' => $message->runId(),
                'session_id' => $message->runId(),
                'component' => 'messenger.worker',
                'event_type' => 'llm.worker_failed.terminal_result_dispatched',
                'step_id' => $message->stepId(),
                'error_type' => \is_string($error['type'] ?? null) ? $error['type'] : 'unknown',
                'error_category' => \is_string($error['error_category'] ?? null) ? $error['error_category'] : null,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('llm.worker_failed.terminal_result_dispatch_failed', [
                'run_id' => $message->runId(),
                'session_id' => $message->runId(),
                'component' => 'messenger.worker',
                'event_type' => 'llm.worker_failed.terminal_result_dispatch_failed',
                'step_id' => $message->stepId(),
                'exception_class' => $exception::class,
            ]);

            // Symfony dispatches WorkerMessageFailedEvent before rejecting the
            // original envelope. Rethrowing keeps ExecuteLlmStep in the transport
            // so it can be reclaimed after run_control delivery recovers.
            throw $exception;
        }
    }

    private function findProviderFailure(\Throwable $throwable): ?RetryableLlmStepFailureException
    {
        $current = $throwable;
        while (null !== $current) {
            if ($current instanceof RetryableLlmStepFailureException) {
                return $current;
            }

            if ($current instanceof WrappedExceptionsInterface) {
                foreach ($current->getWrappedExceptions(null, true) as $wrapped) {
                    $found = $this->findProviderFailure($wrapped);
                    if (null !== $found) {
                        return $found;
                    }
                }
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function genericTerminalError(): array
    {
        $userMessage = 'LLM step result could not be delivered.';

        return [
            'type' => 'llm_step_delivery_failed',
            'message' => $userMessage,
            'retryable' => false,
            'error_category' => 'messenger',
            'user_message' => $userMessage,
        ];
    }

    /**
     * @param array<string, mixed> $error
     *
     * @return array<string, mixed>
     */
    private function sanitizeTerminalError(array $error): array
    {
        $sanitized = [];
        foreach (self::PRIVACY_SAFE_ERROR_KEYS as $key) {
            if (!\array_key_exists($key, $error)) {
                continue;
            }
            $sanitized[$key] = $error[$key];
        }

        $sanitized['retryable'] = false;
        if (!isset($sanitized['user_message']) || !\is_string($sanitized['user_message']) || '' === $sanitized['user_message']) {
            $sanitized['user_message'] = 'LLM provider request failed after retries were exhausted.';
        }

        // Canonical terminal payload keeps only the sanitized user-facing text.
        $sanitized['message'] = $sanitized['user_message'];

        return $sanitized;
    }
}
