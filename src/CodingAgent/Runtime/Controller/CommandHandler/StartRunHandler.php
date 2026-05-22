<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Handles start_run commands.
 *
 * Starts a new agent run via the in-process client and forwards all
 * runtime events emitted during the run.
 *
 * @see CommandHandlerInterface
 */
final readonly class StartRunHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function handle(RuntimeCommand $command, callable $emit): void
    {
        $prompt = (string) ($command->payload['prompt'] ?? '');
        $model = isset($command->payload['model']) ? (string) $command->payload['model'] : null;
        $reasoning = isset($command->payload['reasoning']) ? (string) $command->payload['reasoning'] : null;

        $handle = $this->client->start(new StartRunRequest(
            prompt: $prompt,
            model: '' !== $model ? $model : null,
            reasoning: '' !== $reasoning ? $reasoning : null,
        ));

        $emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        ));

        // Forward all events from the run (blocks until run completes).
        // In ASYNC-05, this is replaced by polling the publish transport.
        foreach ($this->client->events($handle->runId) as $event) {
            $emit($event);
        }
    }
}
