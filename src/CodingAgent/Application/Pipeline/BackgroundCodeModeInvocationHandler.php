<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\MarkCodeModeBackgroundedDTO;

final readonly class BackgroundCodeModeInvocationHandler implements RunMessageHandler
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ToolCallResultHandler $results,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof BackgroundCodeModeInvocationDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof BackgroundCodeModeInvocationDTO) {
            throw new \InvalidArgumentException('Unsupported code-mode background message.');
        }
        if (false !== ($state->pendingToolCalls[$message->toolCallId] ?? null)) {
            return new HandlerResult();
        }
        $metadata = $this->deferred->findMetadataByDeferredId($message->deferredId);
        if (null === $metadata
            || 'pending' !== $metadata->status
            || null === $metadata->readyAt
            || null !== $metadata->backgroundedAt
            || null !== $metadata->acceptedResultReference
            || $metadata->reference->runId !== $state->runId
            || $metadata->reference->toolCallId !== $message->toolCallId
            || $metadata->reference->turnNo !== $state->turnNo
            || $metadata->reference->stepId !== ($state->activeStepId ?? '')
            || 'code_mode' !== $metadata->reference->toolName) {
            return new HandlerResult();
        }
        $correlation = $this->deferred->findByDeferredId($message->deferredId);
        if (null === $correlation) {
            throw new \LogicException('Eligible code-mode background registration is missing.');
        }
        $ack = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
            $correlation,
            [['type' => 'text', 'text' => 'code_mode moved to background. The same host keeps running under its original deadline. Its result will arrive as a follow-up.']],
            ['backgrounded' => true],
            false,
            null,
        );
        $result = $this->results->handle($ack, $state);

        return new HandlerResult(
            $result->nextState,
            array_map(static fn (RunEvent $event): RunEvent => $event->type === RunEventTypeEnum::ToolExecutionEnd->value
                ? new RunEvent($event->runId, $event->seq, $event->turnNo, RunEventTypeEnum::ToolCallAcknowledged->value, $event->payload, $event->createdAt)
                : $event, $result->events),
            $result->effects,
            $result->postCommitEffects,
            [
                new DispatchLocalMetadataMessageDTO(new MarkCodeModeBackgroundedDTO($message->deferredId, new \DateTimeImmutable())),
                ...$result->postCommitActions,
            ],
        );
    }
}
