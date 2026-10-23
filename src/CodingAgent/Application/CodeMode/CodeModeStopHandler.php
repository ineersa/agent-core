<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Application\CodeMode\DTO\InterruptCodeModeDTO;
use Ineersa\CodingAgent\Application\CodeMode\DTO\StopCodeModeDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;

final readonly class CodeModeStopHandler implements RunMessageHandler
{
    public function __construct(private CodeModeExecutionRepository $executions)
    {
    }

    public function supports(object $message): bool
    {
        return $message instanceof StopCodeModeDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof StopCodeModeDTO) {
            throw new \InvalidArgumentException('Unsupported code-mode stop message.');
        }
        if (!$this->executions->canStop($state->runId, $message->identity, $message->toolCallId)) {
            return new HandlerResult();
        }

        // The owner captures one immutable invocation fence, not parent-run
        // cancellation. A blocked nested call observes it only when it yields.
        return new HandlerResult(postCommitActions: [new DispatchLocalMetadataMessageDTO(new InterruptCodeModeDTO([$message->identity], CodeModeInterruptionReasonEnum::Cancelled))]);
    }
}
