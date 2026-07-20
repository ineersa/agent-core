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
 * Used for both Path A (extension approvals / ToolCall continuation) and
 * Path B (ask_human / ModelTurn). The parent sends question_id + answer;
 * this handler routes through AgentSessionClient → ApplyCommand
 * (human_response). Path A resumes the exact stored ExecuteToolCall; Path B
 * appends a human-response message and schedules AdvanceRun.
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
        if (!\is_scalar($answer) || (\is_string($answer) && '' === $answer)) {
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
