<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Handles resume commands.
 *
 * Resumes an existing agent session via the in-process client and forwards
 * all runtime events from the resumed run.
 *
 * @see CommandHandlerInterface
 */
final readonly class ResumeHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function handle(RuntimeCommand $command, callable $emit): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'resume requires runId'],
            ));

            return;
        }

        $handle = $this->client->resume($runId);

        $emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunResumed->value,
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        ));

        // Forward all events from the resumed run.
        foreach ($this->client->events($handle->runId) as $event) {
            $emit($event);
        }
    }
}
