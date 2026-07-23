<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles start_run commands via Symfony EventDispatcher.
 *
 * Dispatches a start_run command to the run_control transport and immediately
 * returns to the event loop. Runtime events from the consumer process are
 * forwarded to TUI via messenger consumer stdout (committed + streaming JSONL).
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class StartRunHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('start_run' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $prompt = (string) ($command->payload['prompt'] ?? '');
        $model = isset($command->payload['model']) ? (string) $command->payload['model'] : null;
        $reasoning = isset($command->payload['reasoning']) ? (string) $command->payload['reasoning'] : null;
        $cwd = isset($command->payload['cwd']) ? (string) $command->payload['cwd'] : '';
        $commandRunId = trim($command->runId ?? '');
        $sessionRunId = 'unknown' !== $event->sessionId ? trim($event->sessionId) : '';
        $runId = $this->resolveRequestedRunId($commandRunId, $sessionRunId);

        // Non-blocking: dispatches StartRun to run_control transport and returns
        // immediately. The run_control consumer picks up the message and processes
        // the run asynchronously. Events flow back through:
        //   1. Consumer stdout (committed RunEvents mapped to RuntimeEvent JSONL)
        //   2. LLM consumer stdout (transient streaming deltas, seq=0)
        // Empty runId is intentional: InProcessAgentSessionClient creates a real
        // pure-digit hatfield_session instead of reusing process-scoping labels.
        $handle = $this->client->start(new StartRunRequest(
            prompt: $prompt,
            runId: $runId,
            cwd: $cwd,
            model: '' !== $model ? $model : null,
            reasoning: '' !== $reasoning ? $reasoning : null,
        ));

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $handle->runId,
            seq: 0,
            payload: ['status' => 'running'],
        ));

        // Events are NOT iterated here — they arrive on consumer stdout pipes and
        // are polled by ConsumerStdoutPoller (canonical seq > 0 and streaming seq=0).
    }

    /**
     * Only pure-digit ids are valid parent execution identities.
     * Non-numeric HATFIELD_SESSION_ID labels are process-scoping only and must
     * not become run ids.
     */
    private function resolveRequestedRunId(string $commandRunId, string $sessionRunId): string
    {
        if ('' !== $commandRunId) {
            if (!ctype_digit($commandRunId)) {
                throw new \RuntimeException(\sprintf('start_run requires a pure-digit hatfield_session runId; got "%s".', $commandRunId));
            }

            return $commandRunId;
        }

        if ('' !== $sessionRunId && ctype_digit($sessionRunId)) {
            return $sessionRunId;
        }

        return '';
    }
}
