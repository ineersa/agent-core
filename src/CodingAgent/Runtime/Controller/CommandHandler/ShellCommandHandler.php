<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Validates and forwards a raw bang command into the AgentCore run pipeline.
 *
 * The handler deliberately does not inspect RunStore, append events, choose a
 * turn, or dispatch the execution worker. ApplyShellCommandHandler performs
 * all of those operations under RunMessageProcessor's run lock and commit.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class ShellCommandHandler
{
    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        $command = $event->command;
        if ('shell_command' !== $command->type) {
            return;
        }

        $runId = trim($command->runId ?? '');
        $rawInput = $command->payload['text'] ?? '';

        $validationError = $this->validationError($runId, $rawInput);
        if (null !== $validationError) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => $validationError],
            ));

            return;
        }

        // Shell-only runs do not emit RunStarted because they bypass start().
        // Register a transient cursor so the controller forwards the canonical
        // command/tool events committed by the run pipeline.
        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $runId,
            seq: 0,
            payload: ['kind' => 'shell'],
        ));

        try {
            $this->commandBus->dispatch(new ApplyShellCommand(
                runId: $runId,
                turnNo: 0,
                stepId: $command->id,
                attempt: 1,
                idempotencyKey: hash('sha256', $runId.'|'.$command->id),
                rawInput: $rawInput,
            ));
        } catch (ExceptionInterface $exception) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: [
                    'error' => 'Failed to dispatch shell command: '.$exception->getMessage(),
                ],
            ));
        }
    }

    private function validationError(string $runId, string $rawInput): ?string
    {
        if ('' === $runId) {
            return 'shell_command requires runId';
        }

        if (!str_starts_with($rawInput, '!')) {
            return 'shell_command text must start with "!"';
        }

        if (str_starts_with($rawInput, '!!')) {
            return '!! is not supported. Use ! to execute shell commands.';
        }

        if ('' === trim(substr($rawInput, 1))) {
            return 'Shell command is empty. Usage: !<command>';
        }

        return null;
    }
}
