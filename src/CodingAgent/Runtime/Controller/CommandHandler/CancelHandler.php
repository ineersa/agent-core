<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\ToolProcessRegistry;
use Ineersa\CodingAgent\Tool\ToolProcessTerminator;
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
        private readonly ?ToolProcessTerminator $processTerminator = null,
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

        // Request asynchronous cancellation through AgentCore.
        $this->client->cancel($runId);

        // Kill foreground tool processes for this run immediately.
        // ToolProcessRegistry is cross-process (locked JSONL), so this works
        // even though the tool consumer is a separate process.
        if (null !== $this->processRegistry && null !== $this->processTerminator) {
            try {
                $foregroundRecords = $this->processRegistry->foregroundForRun($runId);
                if ([] !== $foregroundRecords) {
                    $this->processTerminator->terminateAll($foregroundRecords);
                }
            } catch (\Throwable $e) {
                // Best-effort — don't let registry/terminator errors block cancellation.
            }
        }

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunCancelled->value,
            runId: $runId,
            seq: 0,
        ));
    }
}
