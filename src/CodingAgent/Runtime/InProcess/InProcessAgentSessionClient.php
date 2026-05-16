<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\InProcess;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Infrastructure\Storage\SessionRunEventStore;
use Ineersa\AgentCore\Infrastructure\Storage\SessionRunStore;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;

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
final class InProcessAgentSessionClient implements AgentSessionClient
{
    public function __construct(
        private readonly AgentRunnerInterface $runner,
        private readonly EventStoreInterface $eventStore,
        private readonly RuntimeEventMapper $mapper,
        private readonly SessionRunStore $runStore,
    ) {
    }

    public function start(StartRunRequest $request): RunHandle
    {
        $metadata = null !== $request->model || null !== $request->reasoning
            ? new RunMetadata(model: $request->model, reasoning: $request->reasoning)
            : null;

        $input = new StartRunInput(
            systemPrompt: $request->prompt,
            messages: [],
            runId: '' !== $request->runId ? $request->runId : null,
            metadata: $metadata,
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

    public function initializeSessionsBasePath(string $sessionsBasePath): void
    {
        $this->runStore->setSessionsBasePath($sessionsBasePath);

        if ($this->eventStore instanceof SessionRunEventStore) {
            $this->eventStore->setSessionsBasePath($sessionsBasePath);
        }
    }
}
