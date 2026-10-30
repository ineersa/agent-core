<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Symfony\Component\Uid\UuidV7;

/**
 * Owner-local code_mode deferred registration and readiness.
 *
 * Registration runs in run_control before ExecuteToolCall dispatch. The tool
 * host resolves that prepared identity and never allocates or registers one.
 * Readiness still uses the worker reply client until a later slice removes it.
 */
final class CodeModeLifecycleService
{
    private ?BashRegistrationClient $owner = null;

    public function __construct(private readonly DeferredToolCompletionRepository $deferred)
    {
    }

    public function useOwner(BashRegistrationClient $owner): void
    {
        $this->owner = $owner;
    }

    public function prepare(ExecuteToolCall $call): string
    {
        if ('code_mode' !== $call->toolName) {
            throw new ToolPayloadException('Code-mode registration requires a code_mode invocation.');
        }

        $correlation = new DeferredToolCompletionCorrelation(
            deferredId: UuidV7::v7()->toRfc4122(),
            runId: $call->runId(),
            turnNo: $call->turnNo(),
            stepId: $call->stepId(),
            attempt: $call->attempt(),
            idempotencyKey: $call->idempotencyKey(),
            toolCallId: $call->toolCallId,
            toolName: $call->toolName,
            arguments: $call->args,
            orderIndex: $call->orderIndex,
            toolIdempotencyKey: $call->toolIdempotencyKey,
            mode: $call->mode,
            timeoutSeconds: $call->timeoutSeconds,
            maxParallelism: $call->maxParallelism,
            argSchema: $call->argSchema,
            toolsRef: $call->toolsRef,
        );

        // Producer/run_control never calls DeferredToolCompletionRepository::useOwner(),
        // so registerPending() stays owner-local under the existing run lock.
        return $this->deferred->registerPending($correlation)->deferredId;
    }

    public function ready(string $runId, string $deferredId): void
    {
        if (null !== $this->owner) {
            $this->owner->readyCodeMode($runId, $deferredId);

            return;
        }
        $metadata = $this->deferred->findMetadataByDeferredId($deferredId);
        if (null === $metadata || $metadata->reference->runId !== $runId || 'code_mode' !== $metadata->reference->toolName) {
            throw new ToolPayloadException('Code-mode readiness does not match its registration.');
        }
        $this->deferred->markReady($deferredId);
    }
}
