<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class CodeModeOwnerMessageHandler
{
    public function __construct(private RunLockManager $locks, private OwnerRunInitializationMiddleware $initialization, private RunMessageProcessor $processor, private DeferredToolCompletionRepositoryInterface $deferred, private CompleteDeferredToolCallHandler $completion)
    {
    }

    public function __invoke(CodeModeOwnerDTO $message): void
    {
        $this->locks->synchronized($message->runId(), function () use ($message): void {
            $this->initialization->initializeForOwner($message->runId(), $message);
            $this->processor->process('code_mode', $message);
            $metadata = $this->deferred->findMetadataByDeferredId($message->identity);
            if (null !== $metadata && 'completed' === $metadata->status
                && $metadata->reference->runId === $message->runId()
                && $metadata->reference->toolCallId === $message->toolCallId) {
                // The metadata transaction has finished. Reuse deferred settlement's
                // guarded cleanup here, never delete immutable files inside rollback.
                // Duplicate owner delivery also retries a previously guarded deletion.
                $this->completion->settle($message->identity, $message->runId(), $metadata->reference->turnNo, $metadata->reference->stepId);
            }
        });
    }
}
