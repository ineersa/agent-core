<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Resolves a deferred correlation and publishes the ordinary ToolCallResult.
 *
 * Transport send is not owner acceptance. Deferred status becomes completed only
 * when ToolCallResultHandler commits the accepted terminal lifecycle projection.
 * Original-batch cleanup runs from the after-turn hook after that verified acceptance.
 */
final readonly class CompleteDeferredToolCallHandler
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferredRepository,
        private MessageBusInterface $commandBus,
        private RunLockManager $locks,
        private ToolExecutionEndPayloadCodec $toolExecutionEndPayloadCodec,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(CompleteDeferredToolCall $message): void
    {
        $correlation = $this->deferredRepository->findByDeferredId($message->deferredId);
        if (null === $correlation) {
            $this->logger->warning('deferred_tool_completion.unknown_correlation', [
                'deferred_id' => $message->deferredId,
                'component' => 'tool',
                'event_type' => 'deferred_tool_completion.unknown_correlation',
            ]);

            throw new \RuntimeException(\sprintf('Unknown deferred tool completion id "%s".', $message->deferredId));
        }

        RunLogContext::enter([
            'run_id' => $correlation->runId,
            'session_id' => $correlation->runId,
            'component' => 'tool',
            'event_type' => 'deferred_tool_completion.started',
            'deferred_id' => $message->deferredId,
            'tool_call_id' => $correlation->toolCallId,
        ]);

        try {
            $toolCallResult = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
                $correlation,
                $message->content,
                $message->details,
                $message->isError,
                $message->error,
            );
            $toolCallResult = $this->toolExecutionEndPayloadCodec->finalizeResult($toolCallResult);
            $incomingFingerprint = ToolInvocationAcceptedCompletionIdentity::resultFingerprint(
                $this->toolExecutionEndPayloadCodec->toEventPayload($toolCallResult)['tool_result'],
            );

            if ('completed' === $this->deferredRepository->status($message->deferredId)) {
                $this->assertCompletedNativeMatches($correlation, $incomingFingerprint, $message->deferredId);

                return;
            }

            // Keep the pending correlation until owner acceptance. Successful
            // transport send alone must not mark completed or delete the original
            // batch. Verified acceptance triggers origin-batch cleanup from the
            // after-turn hook using accepted_completion origin identity.
            $this->locks->afterRelease($correlation->runId, function () use ($message, $correlation, $toolCallResult): void {
                try {
                    $this->commandBus->dispatch($toolCallResult);
                } catch (\Throwable $exception) {
                    $this->logger->warning('deferred_tool_completion.delivery_failed', [
                        'run_id' => $correlation->runId,
                        'session_id' => $correlation->runId,
                        'component' => 'deferred_tool_completion',
                        'event_type' => 'deferred_tool_completion.delivery_failed',
                        'deferred_id' => $message->deferredId,
                        'exception_class' => $exception::class,
                    ]);
                }
            });
        } finally {
            RunLogContext::leave();
        }
    }

    private function assertCompletedNativeMatches(
        DeferredToolCompletionCorrelation $correlation,
        string $incomingFingerprint,
        string $deferredId,
    ): void {
        $acceptedFingerprint = $correlation->acceptedResultFingerprint;
        if (null === $acceptedFingerprint || '' === $acceptedFingerprint) {
            throw new \RuntimeException(\sprintf('Completed deferred tool "%s" is missing an accepted result fingerprint.', $deferredId));
        }

        if ($acceptedFingerprint === $incomingFingerprint) {
            return;
        }

        throw new \RuntimeException(\sprintf('Conflicting native deferred completion for run "%s" tool call "%s".', $correlation->runId, $correlation->toolCallId));
    }
}
