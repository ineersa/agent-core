<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Process\ProcessTerminator;
use Ineersa\CodingAgent\Process\ToolProcessRegistry;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles cancel commands via Symfony EventDispatcher.
 *
 * Requests cancellation of the current agent run via the in-process client
 * and terminates any running foreground tool processes for that run.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class CancelHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
        private readonly ?ToolProcessRegistry $processRegistry = null,
        private readonly ?ProcessTerminator $processTerminator = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('cancel' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            return;
        }

        $this->logger->info('Handling cancel command', ['runId' => $runId]);

        // Request asynchronous cancellation through AgentCore.
        $this->client->cancel($runId);

        // Kill foreground tool processes for this run immediately.
        if (null !== $this->processRegistry && null !== $this->processTerminator) {
            try {
                $foregroundRecords = $this->processRegistry->foregroundForRun($runId);

                if ([] !== $foregroundRecords) {
                    $this->logger->info('Terminating foreground tool processes on cancel', [
                        'runId' => $runId,
                        'count' => \count($foregroundRecords),
                        'pids' => array_map(
                            static fn ($r) => ['pid' => $r->pid, 'command' => $r->commandPreview],
                            $foregroundRecords,
                        ),
                    ]);

                    $terminated = $this->processTerminator->terminateAll($foregroundRecords);

                    $this->logger->info('Foreground process termination complete', [
                        'runId' => $runId,
                        'terminated' => $terminated,
                        'total' => \count($foregroundRecords),
                    ]);
                } else {
                    $this->logger->debug('No foreground tool processes to terminate', ['runId' => $runId]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Error terminating foreground tool processes on cancel', [
                    'runId' => $runId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        } else {
            $this->logger->debug('Process registry/terminator not configured; skipping foreground process termination', [
                'runId' => $runId,
            ]);
        }

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunCancelled->value,
            runId: $runId,
            seq: 0,
        ));

        $this->logger->info('Cancel event emitted', ['runId' => $runId]);
    }
}
