<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionAnswerResolver;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles answer_tool_question JSONL commands from the parent TUI process.
 *
 * When the TUI user answers a local tool question (e.g. bash background
 * prompt, SafeGuard approval), the parent sends an answer_tool_question
 * JSONL command with the request_id and answer. This handler writes the
 * answer to the ToolQuestionStore so the blocked tool worker can pick it up.
 *
 * The handler stores answers according to the ToolQuestion's kind:
 * - Confirm-kind (bash bg prompts): stores the boolean answer
 * - Approval-kind (SafeGuard): stores the string answer (e.g. 'Allow once')
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
        private readonly ToolQuestionAnswerResolver $answerResolver = new ToolQuestionAnswerResolver(),
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

        // Determine the question kind to decide whether to store a
        // string answer vs boolean answer.
        $kind = (string) ($command->payload['kind'] ?? '');

        if ('safeguard_approval' === $kind) {
            $this->handleStringAnswer($event, $requestId, $command);

            return;
        }

        // When the payload doesn't carry a kind (or has an unrecognized
        // kind), look up the stored ToolQuestion to infer the correct
        // answer path. This handles: (a) legacy callers that never send
        // kind, and (b) the SafeGuard case where the TUI-side routing
        // sent a different or empty kind by mistake. Without this
        // inference, a safeguard_approval question answered without the
        // proper kind would be stored as boolean false (via
        // handleBooleanAnswer), answer_text stays null, and the blocking
        // poll in ExtensionToolHookEventSubscriber reads null from
        // pollAnswerText and hangs forever.
        if ('' === $kind) {
            $stored = $this->store->findByRequestId($requestId);
            if (null !== $stored && 'safeguard_approval' === $stored->kind) {
                $this->handleStringAnswer($event, $requestId, $command);

                return;
            }
        }

        // Default to boolean for unrecognized or missing kinds, and for
        // stored Confirm-kind questions (backward compat with existing
        // boolean-only confirm callers).
        $this->handleBooleanAnswer($event, $requestId, $command);
    }

    private function handleBooleanAnswer(ControllerCommandEvent $event, string $requestId, RuntimeCommand $command): void
    {
        $answer = $this->answerResolver->resolve($command->payload['answer'] ?? null);

        try {
            $this->store->answer($requestId, $answer);
        } catch (\Throwable $e) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $command->runId ?? '',
                seq: 0,
                payload: [
                    'error' => \sprintf('Failed to answer tool question: %s', $e->getMessage()),
                ],
            ));
        }
    }

    private function handleStringAnswer(ControllerCommandEvent $event, string $requestId, RuntimeCommand $command): void
    {
        $answer = (string) ($command->payload['answer'] ?? '');
        if ('' === $answer) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $command->runId ?? '',
                seq: 0,
                payload: ['error' => 'answer_tool_question with kind=safeguard_approval requires a non-empty answer'],
            ));

            return;
        }

        try {
            $this->store->answerWithText($requestId, $answer);
        } catch (\Throwable $e) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $command->runId ?? '',
                seq: 0,
                payload: [
                    'error' => \sprintf('Failed to answer tool question: %s', $e->getMessage()),
                ],
            ));
        }
    }
}
