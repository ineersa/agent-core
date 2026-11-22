<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;
use Ineersa\CodingAgent\Session\CanonicalToolInvocationReader;
use Ineersa\CodingAgent\Tool\DTO\AgentCancelArgumentsDTO;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

final readonly class AgentCancelTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'agent_cancel';

    public function __construct(
        private StackToolExecutionContextAccessor $context,
        private ToolRuntime $runtime,
        private RunRelationshipReaderInterface $relationships,
        private AgentArtifactRegistry $artifacts,
        private DeferredSubagentChildRepository $children,
        private DeferredSubagentBatchRepository $batches,
        private CanonicalToolInvocationReader $invocations,
        private RunOperationalStatusReaderInterface $statuses,
        private AgentRunnerInterface $runner,
    ) {
    }

    /** @return array{status: 'cancellation_requested', artifact_id: string, agent_run_id: string} */
    public function __invoke(#[MapToolArguments] AgentCancelArgumentsDTO $arguments): array
    {
        return $this->runtime->run(fn (): array => $this->requestCancellation($arguments));
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: 'Request native cancellation of one active direct subagent or fork, including a resumed child. Supply exactly one child handle. Acknowledges the request, not that the child has stopped.',
            handler: $this,
            promptLine: 'agent_cancel artifact_id=<id>|agent_run_id=<uuid> — request cancellation of one active direct child',
            promptGuidelines: ['Call directly, outside code_mode. Unlike agent_steer, cancellation does not depend on the child following instructions. Use agent_retrieve for the final status and any handoff.'],
            mainOnly: true,
        );
    }

    /** @return array{status: 'cancellation_requested', artifact_id: string, agent_run_id: string} */
    private function requestCancellation(AgentCancelArgumentsDTO $arguments): array
    {
        if (1 !== $this->context->depth()) {
            throw new ToolCallException('agent_cancel requires a top-level native tool call and is not supported inside code_mode.', retryable: false);
        }

        $parent = $this->context->requireCurrent()->runId();
        if (RunStatus::Running !== $this->statuses->findOperationalStatus($parent)?->status || $this->relationships->isAgentChild($parent)) {
            throw new ToolCallException('agent_cancel requires an active main run.', retryable: false);
        }

        try {
            $entry = null !== $arguments->artifact_id
                ? $this->artifacts->get($parent, $arguments->artifact_id)
                : $this->artifacts->findByAgentRunId($parent, $arguments->agent_run_id ?? '');
        } catch (\InvalidArgumentException $exception) {
            throw new ToolCallException($exception->getMessage(), retryable: false, previous: $exception);
        }

        if (null === $entry || !$this->artifacts->belongsToCurrentParentLifetime($parent, $entry->artifactId)) {
            throw new ToolCallException('Unknown direct child in the current parent lifetime.', retryable: false);
        }

        $child = $this->children->findByChildRunId($entry->agentRunId);
        $batch = null === $child ? null : $this->batches->findByLifecycleId($child->batchLifecycleId);
        if (null === $batch || $batch->parentRunId !== $parent) {
            throw new ToolCallException('Unknown direct child in the current parent lifetime.', retryable: false);
        }

        // Background work can outlive the originating step, but not its canonical branch.
        $origin = $this->invocations->find($parent, $batch->parentToolCallId, requireCurrentStep: false);
        if (null === $origin || $origin->turnNo !== $batch->parentTurnNo) {
            throw new ToolCallException('Child activity is no longer on the parent’s current branch.', retryable: false);
        }

        $status = $this->statuses->findOperationalStatus($entry->agentRunId)?->status;
        if (!\in_array($status, [RunStatus::Running, RunStatus::Compacting, RunStatus::WaitingHuman, RunStatus::Cancelling], true)) {
            throw new ToolCallException('agent_cancel requires an active child.', retryable: false);
        }

        $this->runner->cancel($entry->agentRunId, 'Child cancellation requested by its parent via agent_cancel.');

        return ['status' => 'cancellation_requested', 'artifact_id' => $entry->artifactId, 'agent_run_id' => $entry->agentRunId];
    }
}
