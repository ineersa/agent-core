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
 * Handles answer_human JSONL commands from the parent TUI process.
 *
 * When the TUI user answers a SafeGuard approval question, the parent
 * sends an answer_human JSONL command with the question_id and answer.
 * This handler dispatches it to the InProcessAgentSessionClient so the
 * answer is routed through the run_control transport, processed by
 * ApplyCommandHandler (human_response → ExtensionApprovalAnswerSubscriber),
 * and delivered back to SafeGuardToolCallHook::onApprovalAnswered().
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class AnswerHumanHandler
{
    public function __construct(
        private readonly AgentSessionClient $client,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('answer_human' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';

        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'answer_human requires runId'],
            ));

            return;
        }

        $questionId = (string) ($command->payload['question_id'] ?? '');
        $answer = $command->payload['answer'] ?? null;

        if ('' === $questionId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => 'answer_human requires question_id'],
            ));

            return;
        }

        // Reject missing or non-scalar answers so that safety-critical
        // approvals are never silently passed through with a null/missing
        // answer value that could be misinterpreted downstream.
        if (!\is_scalar($answer) || '' === (string) $answer) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => 'answer_human requires non-empty answer'],
            ));

            return;
        }

        // Dispatch through InProcessAgentSessionClient so the answer flows
        // through AgentRunner::answerHuman() → CoreCommand(HumanResponse) →
        // run_control consumer → ApplyCommandHandler → extension answer subscriber.
        $this->client->send($runId, new UserCommand(
            type: 'answer_human',
            payload: [
                'question_id' => $questionId,
                'answer' => $answer,
            ],
        ));
    }
}
