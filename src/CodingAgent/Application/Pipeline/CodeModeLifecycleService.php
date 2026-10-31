<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Symfony\Component\Uid\UuidV7;

/**
 * Owner-local code_mode deferred registration and readiness.
 *
 * Registration runs in run_control before ExecuteToolCall dispatch. The tool
 * host resolves that prepared identity and never allocates or registers one.
 * Readiness is applied from one-way ReadyCodeModeInvocationDTO messages.
 */
final class CodeModeLifecycleService
{
    public function __construct(private readonly DeferredToolCompletionRepository $deferred)
    {
    }

    public function prepare(ExecuteToolCall $call): string
    {
        if ('code_mode' !== $call->toolName) {
            throw new ToolPayloadException('Code-mode registration requires a code_mode invocation.');
        }

        $correlation = ToolCallResultFactory::correlationFromExecuteToolCall($call, UuidV7::v7()->toRfc4122());

        // preparePending() stays owner-local under the existing run lock and
        // leaves readyAt null until the host reports readiness.
        return $this->deferred->preparePending($correlation)->deferredId;
    }

    public function ready(string $runId, string $deferredId): void
    {
        $metadata = $this->deferred->findMetadataByDeferredId($deferredId);
        if (null === $metadata || $metadata->reference->runId !== $runId || 'code_mode' !== $metadata->reference->toolName) {
            throw new ToolPayloadException('Code-mode readiness does not match its registration.');
        }
        $this->deferred->markReady($deferredId);
    }
}
