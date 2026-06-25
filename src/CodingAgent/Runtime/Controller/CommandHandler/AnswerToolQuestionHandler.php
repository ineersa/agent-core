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
 * The handler routes the answer by the stored question's schema type, with
 * kind=confirm as a fallback when schema is missing or invalid:
 * - enum schema -> string answer via answerWithText
 * - boolean schema or kind=confirm -> boolean answer via answer()
 * - otherwise -> string answer via answerWithText
 *
 * Explicit schema from the extension (via ToolCallDecisionDTO::requireApproval())
 * is primary; kind=confirm covers malformed/missing confirm schema without
 * rejecting boolean false as an empty string.
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

        // Route by stored schema; kind=confirm is fallback when schema is absent/invalid.
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
        $kind = $stored->kind;

        if ($isEnum) {
            $this->handleStringAnswer($event, $requestId, $command);

            return;
        }

        // Expected production path: explicit boolean schema on the stored question.
        // kind=confirm is defensive local degradation for malformed/missing confirm schema.
        if ($isBoolean || 'confirm' === $kind) {
            $this->handleBooleanAnswer($event, $requestId, $command);

            return;
        }

        $this->handleStringAnswer($event, $requestId, $command);
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
