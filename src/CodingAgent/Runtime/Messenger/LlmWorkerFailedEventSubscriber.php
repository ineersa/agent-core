<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After final llm transport failure for {@see ExecuteLlmStep}, dispatch exactly
 * one sanitized non-retryable {@see LlmStepResult} to run_control so crash /
 * delivery failures still terminalize the run.
 *
 * Application LLM retries are owned by LlmRequestRetryExecutor. This subscriber
 * no longer unwraps provider-classified failures for Messenger redelivery.
 */
final readonly class LlmWorkerFailedEventSubscriber implements EventSubscriberInterface
{
    private const string RECEIVER_NAME = 'llm';

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

        $error = $this->genericTerminalError();
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
            toolsRef: $message->toolsRef,
            model: '',
            reasoning: '',
            modelNotifications: [],
            availableTools: [],
            availableToolsSchemaTokensEstimate: 0,
        );

        try {
            $this->commandBus->dispatch($result);
            $this->logger->info('llm.worker_failed.terminal_result_dispatched', [
                'run_id' => $message->runId(),
                'session_id' => $message->runId(),
                'component' => 'messenger.worker',
                'event_type' => 'llm.worker_failed.terminal_result_dispatched',
                'step_id' => $message->stepId(),
                'error_type' => $error['type'],
                'error_category' => $error['error_category'],
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
}
