<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\AskHuman;

/**
 * Builds normalized ask_human interrupt payloads from validated tool arguments.
 *
 * Argument denormalization/validation is owned by RegistryBackedToolbox
 * (schema + Symfony AI resolver + ValidateToolCallArgumentsListener).
 * This factory only maps the validated DTO into the interrupt transport shape.
 *
 * The answer schema is always derived internally (boolean for kind=confirm,
 * enum from non-empty choices, else string). No raw JSON Schema is accepted
 * as input. The output payload preserves UI metadata (header, choices)
 * alongside the core interrupt fields.
 *
 * AgentCore's ToolExecutor does not fabricate ask_human payloads — it only
 * generically preserves interrupt results returned through the toolbox.
 */
final class AskHumanPayloadFactory
{
    /**
     * @return array<string, mixed> Normalized interrupt payload with kind=interrupt
     */
    public function createPayload(AskHumanArgumentsDTO $dto): array
    {
        return $this->buildPayload($dto);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AskHumanArgumentsDTO $dto): array
    {
        $prompt = $dto->question;
        $questionId = $this->generateQuestionId($dto);
        $schema = $this->resolveSchema($dto);
        $choices = $this->normalizeChoices($dto);
        $kind = $this->resolveKind($dto, $choices);

        $payload = [
            'kind' => 'interrupt',
            'question_id' => $questionId,
            'prompt' => $prompt,
            'schema' => $schema,
            'ui_kind' => $kind,
        ];

        if (null !== $dto->header && '' !== $dto->header) {
            $payload['header'] = $dto->header;
        }

        if ([] !== $choices) {
            $payload['choices'] = $choices;
        }

        return $payload;
    }

    private function generateQuestionId(AskHumanArgumentsDTO $dto): string
    {
        $hashInput = $dto->question;

        if (null !== $dto->kind) {
            $hashInput .= '/kind:'.$dto->kind;
        }

        if (null !== $dto->choices && [] !== $dto->choices) {
            $encoded = json_encode($dto->choices, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $hashInput .= '/choices:'.(\is_string($encoded) ? $encoded : '');
        }

        if (null !== $dto->header && '' !== $dto->header) {
            $hashInput .= '/header:'.$dto->header;
        }

        return 'ah_'.substr(hash('sha256', $hashInput), 0, 24);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSchema(AskHumanArgumentsDTO $dto): array
    {
        if ('confirm' === $dto->kind) {
            return ['type' => 'boolean'];
        }

        $choices = $dto->choices ?? [];
        if ([] !== $choices) {
            return ['type' => 'string', 'enum' => $choices];
        }

        return ['type' => 'string'];
    }

    /**
     * @param list<array{label: string, description: string}> $choices
     */
    private function resolveKind(AskHumanArgumentsDTO $dto, array $choices): string
    {
        if (null !== $dto->kind && '' !== $dto->kind) {
            return $dto->kind;
        }

        if ([] !== $choices) {
            return 'choice';
        }

        return 'text';
    }

    /**
     * @return list<array{label: string, description: string}>
     */
    private function normalizeChoices(AskHumanArgumentsDTO $dto): array
    {
        $raw = $dto->choices ?? [];
        if ([] === $raw) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $item) {
            $normalized[] = ['label' => $item, 'description' => ''];
        }

        return $normalized;
    }
}
