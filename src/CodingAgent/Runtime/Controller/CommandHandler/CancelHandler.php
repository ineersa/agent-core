<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Handles cancel commands.
 *
 * Requests cancellation of the current agent run via the in-process client.
 *
 * @see CommandHandlerInterface
 */
final readonly class CancelHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function handle(RuntimeCommand $command, callable $emit): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            return;
        }

        $this->client->cancel($runId);

        $emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunCancelled->value,
            runId: $runId,
            seq: 0,
        ));
    }
}
