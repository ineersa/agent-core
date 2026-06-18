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
 * prompt or an extension's approval prompt), the parent sends an
 * answer_tool_question JSONL command with the request_id and answer.
 * This handler writes the answer to the ToolQuestionStore so the blocked
 * tool worker can pick it up.
 *
 * The handler routes the answer by the STORED question's schema type:
 * - boolean schema (type=boolean) -> stores the boolean answer (legacy confirm path)
 * - string/enum schema -> stores the string answer via answerWithText
 *
 * This is schema-driven, not kind-driven — the handler contains ZERO
 * references to any specific extension. The extension supplies the
 * schema via ToolCallDecisionDTO::requireApproval(); the infra routes
 * generically.
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

        // Route by the stored question's schema type — no kind-based routing.
        // The extension's schema (supplied via requireApproval) determines
        // whether the answer is stored as boolean or string.
        $stored = $this->store->findByRequestId($requestId);

        if (null === $stored) {
            // No stored question found — try payload-based routing,
            // falling back to boolean for backward compat.
            $kind = (string) ($command->payload['kind'] ?? '');
            if ('approval' === $kind) {
                $this->handleStringAnswer($event, $requestId, $command);

                return;
            }

            $this->handleBooleanAnswer($event, $requestId, $command);

            return;
        }

        // Parse the stored schema to determine the answer type.
        $schema = $this->parseSchema($stored->schema);
        $isEnum = isset($schema['enum']) && \is_array($schema['enum']) && [] !== $schema['enum'];
        $isBoolean = ($schema['type'] ?? '') === 'boolean';

        if ($isEnum || !$isBoolean) {
            // Enum/string schema → store as text answer
            $this->handleStringAnswer($event, $requestId, $command);

            return;
        }

        // Boolean schema → store as boolean answer
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
                payload: ['error' => 'answer_tool_question requires a non-empty answer for string/enum questions'],
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

    /**
     * Parse a stored schema value (JSON string or array) into an array.
     *
     * @return array<string, mixed>
     */
    private function parseSchema(mixed $schema): array
    {
        if (\is_array($schema)) {
            return $schema;
        }

        if (\is_string($schema) && '' !== $schema) {
            $decoded = json_decode($schema, true);

            return \is_array($decoded) ? $decoded : ['type' => 'string'];
        }

        return ['type' => 'string'];
    }
}
