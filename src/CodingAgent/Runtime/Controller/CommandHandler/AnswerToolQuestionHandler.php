<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles answer_tool_question JSONL commands from the parent TUI process.
 *
 * When the TUI user answers a local tool question (e.g. bash background
 * prompt), the parent sends an answer_tool_question JSONL command with
 * the request_id and answer. This handler writes the answer to the
 * ToolQuestionStore so the blocked tool worker can pick it up.
 *
 * This is the controller-side counterpart to AnswerHumanHandler but for
 * local tool questions that must NOT go through answer_human, WaitingHuman,
 * or transcript projection.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class AnswerToolQuestionHandler
{
    public function __construct(
        private readonly ToolQuestionStoreInterface $store,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('answer_tool_question' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';

        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'answer_tool_question requires runId'],
            ));

            return;
        }

        $requestId = (string) ($command->payload['request_id'] ?? '');

        if ('' === $requestId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => 'answer_tool_question requires request_id'],
            ));

            return;
        }

        $answer = $this->resolveAnswer($command->payload['answer'] ?? null);

        try {
            $this->store->answer($requestId, $answer);
        } catch (\Throwable $e) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: [
                    'error' => \sprintf('Failed to answer tool question: %s', $e->getMessage()),
                ],
            ));
        }
    }

    /**
     * Resolve a boolean from various answer formats.
     *
     * Accepts: bool, 'yes'/'no'/'true'/'false'/'1'/'0', int 1/0.
     */
    private function resolveAnswer(mixed $answer): bool
    {
        if (\is_bool($answer)) {
            return $answer;
        }

        if (\is_string($answer)) {
            $lower = strtolower(trim($answer));

            return \in_array($lower, ['yes', 'true', '1'], true);
        }

        if (\is_int($answer)) {
            return 1 === $answer;
        }

        return false;
    }
}
