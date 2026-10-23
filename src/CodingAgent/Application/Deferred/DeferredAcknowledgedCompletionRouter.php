<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredAcknowledgedCompletionRouterInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/** Route acknowledged deferred terminals through existing background-completion admission. */
final readonly class DeferredAcknowledgedCompletionRouter implements DeferredAcknowledgedCompletionRouterInterface
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private CodeModeExecutionRepository $codeModeExecutions,
        private CodeModeExecutionService $codeModeExecution,
        private CodeModeResultArtifactStore $codeModeArtifacts,
    ) {
    }

    public function deliver(string $deferredId, ToolCallResult $result, bool $explicitContinuation = false): void
    {
        $text = implode("\n", array_column($result->result['content'] ?? [], 'text'));
        $row = $this->codeModeExecutions->invocation($result->runId(), $result->toolCallId);
        if (null !== $row && $row['identity'] === $deferredId) {
            $text = \sprintf("Code-mode result (run: %s, tool call: %s, invocation: %s):\n%s", $result->runId(), $result->toolCallId, $deferredId, $text);
            $accepted = $this->deferred->findAcceptedResultReferenceByDeferredId($deferredId);
            if (null !== $accepted) {
                $text .= "\nRetained code-mode result: ".$this->codeModeArtifacts->path($accepted);
            }
            if (null !== $row['raw_reference'] && '' !== $row['raw_reference']) {
                $text .= "\nOriginal structured value and diagnostics: ".$this->codeModeArtifacts->path(
                    $this->codeModeExecution->resultReference($row['raw_reference']),
                );
            }
            $identity = 'background-'.$deferredId;
        } else {
            $identity = 'background-child-result-'.$deferredId;
        }

        $this->commandBus->dispatch(new DeliverBackgroundCompletionDTO($result->runId(), $identity, $text, explicitContinuation: $explicitContinuation));
    }
}
