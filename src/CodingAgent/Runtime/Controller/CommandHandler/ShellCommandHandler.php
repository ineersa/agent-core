<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles shell_command commands via Symfony EventDispatcher.
 *
 * Receives shell_command RuntimeCommands from the TUI (via JSONL),
 * delegates to InProcessAgentSessionClient which executes bash through
 * the shared tool executor and emits canonical tool_execution events.
 *
 * Shell output appears in the transcript via the controller's periodic
 * EventStore drain — no LLM turn is triggered.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class ShellCommandHandler
{
    public function __construct(
        private readonly AgentSessionClient $client,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('shell_command' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'shell_command requires runId'],
            ));

            return;
        }

        $commandText = (string) ($command->payload['text'] ?? '');

        // Delegate to the in-process client which executes bash through
        // the shared tool executor and persists tool_execution events to
        // the canonical event store. The controller's periodic EventStore
        // drain will pick them up and forward to TUI via the emitter.
        $this->client->send($runId, new UserCommand(
            type: 'shell_command',
            text: $commandText,
        ));
    }
}
