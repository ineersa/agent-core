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
 * Used for local boolean tool questions (e.g. bash background prompts).
 * Extension/SafeGuard approvals use canonical answer_human + WaitingHuman —
 * not this command path.
 *
 * Routes by stored schema with kind=confirm fallback:
 * - boolean schema or kind=confirm -> boolean answer via answer()
 * - anything else is rejected (string/enum approval answers are not supported here)
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

        $stored = $this->store->findByRequestId($requestId);
        if (null === $stored) {
            // No stored question — boolean only (bash path).
            $this->handleBooleanAnswer($event, $requestId, $command);

            return;
        }

        $schema = $this->parseSchema($stored->schema);
        $isBoolean = ($schema['type'] ?? '') === 'boolean';
        $kind = $stored->kind;

        if ($isBoolean || 'confirm' === $kind) {
            $this->handleBooleanAnswer($event, $requestId, $command);

            return;
        }

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::ProtocolError->value,
            runId: $runId,
            seq: 0,
            payload: ['error' => 'answer_tool_question only supports boolean/confirm tool questions'],
        ));
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

    /**
     * @return array<string, mixed>
     */
    private function parseSchema(mixed $schema): array
    {
        if (\is_array($schema)) {
            return $schema;
        }

        if (\is_string($schema) && '' !== $schema) {
            $decoded = json_decode($schema, true);

            return \is_array($decoded) ? $decoded : ['type' => 'boolean'];
        }

        return ['type' => 'boolean'];
    }
}
