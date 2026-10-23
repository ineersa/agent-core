<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\CodeMode\DTO\MarkCodeModeDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;

final readonly class CodeModeOwnerHandler implements RunMessageHandler
{
    public function __construct(private CodeModeExecutionRepository $executions, private CodeModeExecutionService $execution, private DeferredToolCompletionRepositoryInterface $deferred, private ToolCallResultHandler $results, private CodeModeResultArtifactStore $artifacts, private ToolBatchStoreInterface $batches)
    {
    }

    public function supports(object $message): bool
    {
        return $message instanceof CodeModeOwnerDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof CodeModeOwnerDTO) {
            throw new \InvalidArgumentException('Unsupported code-mode owner message.');
        }
        $row = $this->executions->invocation($state->runId, $message->toolCallId);
        if (null === $row || $message->identity !== $row['identity'] || (bool) $row['settled']) {
            return new HandlerResult();
        }
        $call = $this->execution->invocation($row['call_reference']);
        if ($call->runId() !== $state->runId || $call->toolCallId !== $message->toolCallId || 'code_mode' !== $call->toolName) {
            throw new \RuntimeException('Code-mode owner does not match its frozen invocation.');
        }
        if ($message->acknowledge) {
            if (!(bool) $row['ready'] || (bool) $row['detached'] || null !== $row['result_reference'] || false !== ($state->pendingToolCalls[$message->toolCallId] ?? null)) {
                return new HandlerResult();
            }
            if ($call->turnNo() !== $state->turnNo || $call->stepId() !== $state->activeStepId
                || !$this->execution->sameInvocation($call, $this->batches->loadCall($state->runId, $state->turnNo, $call->stepId(), $message->toolCallId))
                || (array) $this->execution->resultReference($row['call_reference']) !== (array) $this->batches->loadCallReference($state->runId, $state->turnNo, $call->stepId(), $message->toolCallId)) {
                return new HandlerResult();
            }
            $correlation = $this->deferred->findByDeferredId($message->identity);
            if (null === $correlation) {
                throw new \RuntimeException('Live code-mode invocation lost its correlation.');
            }
            $ack = ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, [['type' => 'text', 'text' => 'Code-mode invocation moved to background. The original host and worker remain occupied under the original budget. Its result will arrive as a follow-up.']]);
            if (!$this->execution->matches($ack, $call)) {
                throw new \RuntimeException('Code-mode correlation changed before acknowledgement.');
            }
            $result = $this->results->acknowledge($ack, $state);
            if ([] === $result->events) {
                return $result;
            }

            return new HandlerResult($result->nextState, $result->events, $result->effects, $result->postCommitEffects, [new DispatchLocalMetadataMessageDTO(new MarkCodeModeDTO($message->identity, true)), ...$result->postCommitActions]);
        }
        if (null === $row['result_reference']) {
            return new HandlerResult();
        }
        $result = $this->artifacts->read($this->execution->resultReference($row['result_reference']));
        if (!$this->execution->matches($result, $call)) {
            throw new \RuntimeException('Code-mode result does not match its frozen invocation.');
        }
        $mark = new DispatchLocalMetadataMessageDTO(new MarkCodeModeDTO($message->identity, false));
        if ((bool) $row['detached']) {
            $text = implode("\n", array_column($result->result['content'] ?? [], 'text'));
            $text .= "\nRetained code-mode result: ".$this->artifacts->path($this->execution->resultReference($row['result_reference']));
            if (null !== $row['raw_reference']) {
                $text .= "\nOriginal structured value and diagnostics: ".$this->artifacts->path($this->execution->resultReference($row['raw_reference']));
            }

            return new HandlerResult(postCommitEffects: [new DeliverBackgroundCompletionDTO($state->runId, 'background-'.$message->identity, $text)], postCommitActions: [$mark]);
        }
        $handled = $this->results->handle($result, $state);

        return new HandlerResult($handled->nextState, $handled->events, $handled->effects, $handled->postCommitEffects, [...$handled->postCommitActions, $mark]);
    }
}
