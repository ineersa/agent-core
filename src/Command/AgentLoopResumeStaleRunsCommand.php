<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Command;

use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * This command identifies and resumes stale agent runs by coordinating with storage and locking mechanisms to ensure safe execution. It leverages a replay service to restore state and dispatches commands via the message bus to continue processing.
 */
#[AsCommand(name: 'agent-loop:resume-stale-runs', description: 'Resume stale running runs after worker restart.')]
final class AgentLoopResumeStaleRunsCommand extends Command
{
    /**
     * Injects required stores, services, and bus for stale run resumption.
     */
    public function __construct(
        private readonly RunStoreInterface $runStore,
        private readonly PromptStateStoreInterface $promptStateStore,
        private readonly ReplayService $replayService,
        private readonly RunLockManager $runLockManager,
        private readonly MessageBusInterface $commandBus,
        private readonly int $staleAfterSeconds = 120,
    ) {
        parent::__construct();
    }

    /**
     * Identifies stale runs, resumes them via replay service, and dispatches commands.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $olderThanSeconds = $this->staleAfterSeconds;
        $updatedBefore = (new \DateTimeImmutable())->setTimestamp(time() - $olderThanSeconds);
        $staleRuns = $this->runStore->findRunningStaleBefore($updatedBefore);

        $inspected = 0;
        $resumed = 0;
        $rebuiltHotState = 0;
        $skipped = 0;

        foreach ($staleRuns as $staleRun) {
            ++$inspected;

            $this->runLockManager->synchronized($staleRun->runId, function () use (&$rebuiltHotState, &$resumed, &$skipped, $staleRun): void {
                $currentState = $this->runStore->get($staleRun->runId);
                if (null === $currentState || RunStatus::Running !== $currentState->status) {
                    ++$skipped;

                    return;
                }

                if (null === $this->promptStateStore->get($currentState->runId)) {
                    $this->replayService->rebuildHotPromptState($currentState->runId);
                    ++$rebuiltHotState;
                }

                $stepId = \sprintf('resume-stale-%d', hrtime(true));

                try {
                    $this->commandBus->dispatch(new AdvanceRun(
                        runId: $currentState->runId,
                        turnNo: $currentState->turnNo,
                        stepId: $stepId,
                        attempt: 1,
                        idempotencyKey: hash('sha256', \sprintf('%s|%s', $currentState->runId, $stepId)),
                    ));
                } catch (ExceptionInterface $exception) {
                    throw new \RuntimeException('Failed to dispatch AdvanceRun while resuming stale run.', previous: $exception);
                }

                ++$resumed;
            });
        }

        $io->definitionList(
            ['stale_after_seconds' => (string) $olderThanSeconds],
            ['stale_runs_inspected' => (string) $inspected],
            ['resumed' => (string) $resumed],
            ['hot_state_rebuilt' => (string) $rebuiltHotState],
            ['skipped' => (string) $skipped],
        );

        $io->success('Stale run resume scan finished.');

        return self::SUCCESS;
    }
}
