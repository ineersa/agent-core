<?php

declare(strict_types=1);

namespace App\Runtime\InProcess;

use App\Runtime\Contract\AgentSessionClient;
use App\Runtime\Contract\RunHandle;
use App\Runtime\Contract\StartRunRequest;
use App\Runtime\Contract\UserCommand;
use App\Runtime\Protocol\RuntimeEvent;
use App\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\StartRunInput;

/**
 * In-process implementation of AgentSessionClient.
 *
 * Calls agent-core services directly within the same process.
 * All data is still mapped through RuntimeEvent protocol DTOs so that
 * TUI code never sees agent-core domain objects.
 *
 * This is the default transport during development. It must stay
 * behaviorally equivalent to JsonlProcessAgentSessionClient.
 */
final readonly class InProcessAgentSessionClient implements AgentSessionClient
{
    public function __construct(
        private AgentRunnerInterface $runner,
        private EventStoreInterface $eventStore,
        private RuntimeEventMapper $mapper,
    ) {
    }

    public function start(StartRunRequest $request): RunHandle
    {
        $input = new StartRunInput(
            systemPrompt: $request->prompt,
            messages: [],
            runId: null,
        );

        $runId = $this->runner->start($input);

        return new RunHandle(runId: $runId, status: 'running');
    }

    public function resume(string $runId): RunHandle
    {
        $this->runner->continue($runId);

        return new RunHandle(runId: $runId, status: 'running');
    }

    public function send(string $runId, UserCommand $command): void
    {
        match ($command->type) {
            'steer', 'message' => $this->runner->steer(
                $runId,
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => $command->text ?? '']],
                ),
            ),
            'follow_up' => $this->runner->followUp(
                $runId,
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => $command->text ?? '']],
                ),
            ),
            'answer_human' => $this->runner->answerHuman(
                $runId,
                (string) ($command->payload['question_id'] ?? ''),
                $command->payload['answer'] ?? null,
            ),
            default => throw new \InvalidArgumentException(\sprintf('Unknown UserCommand type: "%s"', $command->type)),
        };
    }

    public function events(string $runId): iterable
    {
        $runEvents = $this->eventStore->allFor($runId);

        foreach ($runEvents as $runEvent) {
            yield $this->mapper->toRuntimeEvent($runEvent);
        }
    }

    public function cancel(string $runId): void
    {
        $this->runner->cancel($runId);
    }
}
