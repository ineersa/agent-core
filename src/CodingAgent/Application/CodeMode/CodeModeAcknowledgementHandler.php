<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\Deferred\DeferredCallerAcknowledgementService;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;

/** Native readiness gate for shared deferred caller acknowledgement. */
final readonly class CodeModeAcknowledgementHandler implements RunMessageHandler
{
    public function __construct(
        private CodeModeExecutionRepository $executions,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private DeferredCallerAcknowledgementService $acknowledgements,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof CodeModeOwnerDTO && $message->acknowledge;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof CodeModeOwnerDTO || !$message->acknowledge) {
            throw new \InvalidArgumentException('Unsupported code-mode acknowledgement message.');
        }
        $row = $this->executions->invocation($state->runId, $message->toolCallId);
        if (null === $row || $message->identity !== $row['identity'] || !(bool) $row['ready'] || null !== $row['interruption_reason']) {
            return new HandlerResult();
        }
        if (null !== $this->deferred->acknowledgedAt($message->identity)
            || null !== $this->deferred->findAcceptedResultReferenceByDeferredId($message->identity)) {
            return new HandlerResult();
        }

        return $this->acknowledgements->acknowledge(
            $message->identity,
            $state,
            [['type' => 'text', 'text' => 'Code-mode invocation moved to background. The original host and worker remain occupied under the original budget. Its result will arrive as a follow-up.']],
        );
    }
}
