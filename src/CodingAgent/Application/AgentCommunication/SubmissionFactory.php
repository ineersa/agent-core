<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication;

use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\SubmissionInputDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\SubmissionReceiptDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\CanonicalToolInvocationReader;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Resolves a direct edge from native activity evidence, never from authored text. */
final readonly class SubmissionFactory
{
    public function __construct(
        private AgentArtifactRegistry $artifacts,
        private DeferredSubagentChildRepository $children,
        private DeferredSubagentBatchRepository $batches,
        private RunOperationalStatusReaderInterface $statuses,
        private CanonicalToolInvocationReader $invocations,
        private SerializerInterface $serializer,
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public function defer(ToolContext $context, array $arguments): DeferredToolCompletionOutcome
    {
        $receipt = $this->prepare($context->runId(), $context->turnNo(), $context->stepId() ?? '', $context->toolCallId(), $context->toolName(), $arguments);

        // The id freezes the exact edge at execution. Owner acceptance recomputes
        // it, so queued work cannot silently address a newer resume generation.
        return new DeferredToolCompletionOutcome($receipt->identity);
    }

    /** @param array<string, mixed> $arguments */
    public function prepare(string $runId, int $turnNo, string $stepId, string $toolCallId, string $toolName, array $arguments): SubmissionReceiptDTO
    {
        if (!\in_array($toolName, ['agent_steer', 'notify_parent'], true)) {
            throw new ToolCallException('Unsupported agent communication tool.', retryable: false);
        }
        $input = $this->denormalizer->denormalize($arguments, SubmissionInputDTO::class, context: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false]);
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($input, $violations);
        }
        $source = $this->invocations->find($runId, $toolCallId);
        if (null === $source || $source->turnNo !== $turnNo || $source->stepId !== $stepId
            || RunStatus::Running !== $this->statuses->findOperationalStatus($runId)?->status) {
            throw new ToolCallException('Agent submission source invocation is no longer active.', retryable: false);
        }
        if ('agent_steer' === $toolName) {
            if ((null === $input->artifact_id) === (null === $input->agent_run_id)) {
                throw new ToolCallException('agent_steer requires exactly one child handle.', retryable: false);
            }
            try {
                $entry = null !== $input->artifact_id
                    ? $this->artifacts->get($runId, $input->artifact_id)
                    : $this->artifacts->findByAgentRunId($runId, $input->agent_run_id);
            } catch (\InvalidArgumentException $exception) {
                throw new ToolCallException($exception->getMessage(), retryable: false);
            }
            if (null === $entry) {
                throw new ToolCallException('Unknown direct child in the current parent session.', retryable: false);
            }
            $child = $this->children->findByChildRunId($entry->agentRunId);
        } else {
            if (null !== $input->artifact_id || null !== $input->agent_run_id) {
                throw new ToolCallException('notify_parent derives its destination from runtime identity.', retryable: false);
            }
            $child = $this->children->findByChildRunId($runId);
        }
        $batch = null === $child ? null : $this->batches->findByLifecycleId($child->batchLifecycleId);
        if (null === $child || null === $batch || ('agent_steer' === $toolName && $batch->parentRunId !== $runId)
            || null !== $batch->interruptionKind || null !== $batch->completionSource || null !== $batch->terminalCompletionEnqueuedAt
            || !$this->artifacts->belongsToCurrentParentLifetime($batch->parentRunId, $child->artifactId)) {
            throw new ToolCallException('Direct child activity is no longer authorized.', retryable: false);
        }
        $childStatus = $this->statuses->findOperationalStatus($child->childRunId)?->status;
        if (!\in_array($childStatus, [RunStatus::Running, RunStatus::WaitingHuman], true)) {
            throw new ToolCallException('Agent communication requires an active child.', retryable: false);
        }
        $parentStatus = $this->statuses->findOperationalStatus($batch->parentRunId)?->status;
        $allowedParent = \in_array($parentStatus, [RunStatus::Running, RunStatus::WaitingHuman, RunStatus::Compacting], true)
            || ('notify_parent' === $toolName && RunStatus::Completed === $parentStatus && null !== $batch->backgroundedAt);
        // Detached children can outlive their originating step, but never the
        // selected canonical ancestry. This lookup does not reconstruct RunState.
        $origin = $this->invocations->find($batch->parentRunId, $batch->parentToolCallId, requireCurrentStep: false);
        if (!$allowedParent || null === $origin || $origin->turnNo !== $batch->parentTurnNo) {
            throw new ToolCallException('Parent activity was cancelled, failed, or discarded.', retryable: false);
        }
        $identity = 'agent-submission-'.hash('sha256', $this->serializer->serialize([
            $runId, $turnNo, $stepId, $toolCallId, $toolName,
            $batch->parentRunId, $child->childRunId, $batch->lifecycleId, $input->text,
        ], 'json'));

        return new SubmissionReceiptDTO($identity, $runId, $toolCallId, $toolName, $batch->parentRunId, $child->childRunId, $batch->lifecycleId, $input->text, null !== $batch->backgroundedAt);
    }
}
