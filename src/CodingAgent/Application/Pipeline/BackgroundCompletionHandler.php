<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\CodingAgent\Application\Deferred\DeferredBackgroundCompletionSettlement;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Enter native completion admission through normal owner transition recovery. */
#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class BackgroundCompletionHandler
{
    public function __construct(
        private RunLockManager $locks,
        private OwnerRunInitializationMiddleware $initialization,
        private RunMessageProcessor $processor,
        private DeferredBackgroundCompletionSettlement $deferredSettlement,
    ) {
    }

    public function __invoke(DeliverBackgroundCompletionDTO $message): void
    {
        $runId = $message->runId();
        $this->locks->synchronized($runId, function () use ($message, $runId): void {
            $this->initialization->initializeForOwner($runId, $message);
            $this->processor->process('background_completion', $message);
            // Receipt/status metadata has committed and canonical finalization
            // succeeded. Duplicate admission also retries guarded file cleanup.
            $this->deferredSettlement->cleanup($message->identity);
        });
    }
}
