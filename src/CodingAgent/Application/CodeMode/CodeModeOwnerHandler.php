<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\CodeMode\DTO\MarkCodeModeDTO;
use Ineersa\CodingAgent\Application\Deferred\DeferredCallerAcknowledgementService;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;

final readonly class CodeModeOwnerHandler implements RunMessageHandler
{
    public function __construct(
        private CodeModeExecutionRepository $executions,
        private CodeModeExecutionService $execution,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private DeferredCallerAcknowledgementService $acknowledgements,
        private ToolCallResultHandler $results,
        private CodeModeResultArtifactStore $artifacts,
    ) {
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
            if (!(bool) $row['ready'] || null !== $row['result_reference'] || null !== $this->deferred->acknowledgedAt($message->identity)) {
                return new HandlerResult();
            }

            return $this->acknowledgements->acknowledge(
                $message->identity,
                $state,
                [['type' => 'text', 'text' => 'Code-mode invocation moved to background. The original host and worker remain occupied under the original budget. Its result will arrive as a follow-up.']],
            );
        }
        if (null === $row['result_reference']) {
            return new HandlerResult();
        }
        $result = $this->artifacts->read($this->execution->resultReference($row['result_reference']));
        if (!$this->execution->matches($result, $call)) {
            throw new \RuntimeException('Code-mode result does not match its frozen invocation.');
        }
        $mark = new DispatchLocalMetadataMessageDTO(new MarkCodeModeDTO($message->identity));
        if (null !== $this->deferred->acknowledgedAt($message->identity)) {
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
