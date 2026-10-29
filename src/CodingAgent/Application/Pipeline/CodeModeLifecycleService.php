<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;

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

    public function register(DeferredToolCompletionCorrelation $invocation): string
    {
        if ('code_mode' !== $invocation->toolName) {
            throw new ToolPayloadException('Code-mode registration requires a code_mode invocation.');
        }

        // The invocation identity, not the requester incarnation or proposed ID,
        // deduplicates retries. No process is launched by this metadata operation.
        return null !== $this->owner ? $this->owner->registerCodeMode($invocation) : $this->deferred->registerPending($invocation)->deferredId;
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
