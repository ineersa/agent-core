<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\CompleteSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\SubmissionReceiptDTO;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class SubmissionRunHandler implements RunMessageHandler
{
    public function __construct(private DeferredToolCompletionRepositoryInterface $deferred, private SubmissionFactory $factory, private SubmissionReceiptRepository $receipts, private EventFactory $events, private NormalizerInterface $normalizer)
    {
    }

    public function supports(object $message): bool
    {
        return $message instanceof AcceptSubmissionDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof AcceptSubmissionDTO || $message->runId() !== $state->runId) {
            throw new \InvalidArgumentException('Unsupported agent submission owner.');
        }
        $receipt = $this->receipts->receipt($state->runId, $message->identity);
        if (null !== $receipt) {
            if ($receipt->sourceRunId !== $state->runId) {
                throw new \RuntimeException('Agent submission receipt belongs to another source.');
            }

            // A duplicate returns the original receipt, even if this child has
            // since resumed. It never reauthorizes or creates another submission.
            return new HandlerResult(postCommitEffects: [$this->completion($receipt)]);
        }
        $source = $this->deferred->findByDeferredId($message->identity);
        if (null === $source || $source->runId !== $state->runId
            || !\in_array($source->toolName, ['agent_steer', 'notify_parent'], true)) {
            throw new \RuntimeException('Agent submission lacks its native deferred correlation.');
        }
        if (RunStatus::Running !== $state->status || $state->turnNo !== $source->turnNo
            || $state->activeStepId !== $source->stepId || false !== ($state->pendingToolCalls[$source->toolCallId] ?? null)) {
            return $this->refuse($state->runId, $source->deferredId, 'Source invocation is no longer active.');
        }
        try {
            $receipt = $this->factory->prepare($source->runId, $source->turnNo, $source->stepId, $source->toolCallId, $source->toolName, $source->arguments);
            if ($receipt->identity !== $source->deferredId) {
                return $this->refuse($state->runId, $source->deferredId, 'Direct child activity changed before source acceptance.');
            }
        } catch (ToolCallException|ValidationFailedException|ExceptionInterface $exception) {
            // Intentional refusal is propagated as a failed native tool result;
            // no source receipt or receiver obligation was accepted.
            return $this->refuse($state->runId, $source->deferredId, $exception->getMessage());
        }
        $event = $this->events->event($state->runId, $state->lastSeq + 1, $state->turnNo, RunEventTypeEnum::ToolExecutionUpdate->value, [
            'tool_call_id' => $source->toolCallId,
            'tool_name' => $source->toolName,
            'submission_receipt' => $this->normalizer->normalize($receipt),
        ]);

        return new HandlerResult(
            $state->with(['lastSeq' => $state->lastSeq + 1, 'version' => $state->version + 1]), [$event],
            postCommitEffects: [$this->completion($receipt)],
        );
    }

    private function completion(SubmissionReceiptDTO $receipt): CompleteSubmissionDTO
    {
        return new CompleteSubmissionDTO($receipt->sourceRunId, new CompleteDeferredToolCall($receipt->identity, [['type' => 'text', 'text' => 'Submission persisted at source. This does not confirm receiver delivery or attention.']], [
            'submission_id' => $receipt->identity,
            'status' => 'source_accepted',
        ]));
    }

    private function refuse(string $runId, string $identity, string $reason): HandlerResult
    {
        return new HandlerResult(postCommitEffects: [new CompleteSubmissionDTO($runId, new CompleteDeferredToolCall($identity, [['type' => 'text', 'text' => $reason]], ['status' => 'source_refused'], true))]);
    }
}
