<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\AskHuman;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Builds normalized ask_human interrupt payloads from raw tool call arguments.
 *
 * Uses Symfony Serializer for type-safe argument denormalization and Symfony
 * Validator for upfront validation. The output payload preserves all UI
 * metadata (header, choices, default, allow_other, secret) alongside the
 * core interrupt fields (kind, question_id, prompt, schema, ui_kind).
 *
 * The factory is the single canonical source of payload normalization.
 * AgentCore's ToolExecutor does not fabricate ask_human payloads — it only
 * generically preserves interrupt results returned through the toolbox.
 */
final class AskHumanPayloadFactory
{
    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Build a normalized interrupt payload from raw tool call arguments.
     *
     * @param array<string, mixed> $arguments Raw tool call arguments
     *
     * @return array<string, mixed> Normalized interrupt payload with kind=interrupt
     *
     * @throws ToolCallException When arguments fail validation
     */
    public function createPayload(array $arguments): array
    {
        try {
            /** @var AskHumanArgumentsDTO $dto */
            $dto = $this->denormalizer->denormalize($arguments, AskHumanArgumentsDTO::class);
        } catch (\Throwable $e) {
            throw new ToolCallException('Invalid ask_human arguments: '.$e->getMessage(), retryable: false);
        }

        $violations = $this->validator->validate($dto);
        if ($violations->count() > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $path = $violation->getPropertyPath();
                if ('' !== $path) {
                    $messages[] = $path.': '.$violation->getMessage();
                } else {
                    $messages[] = $violation->getMessage();
                }
            }

            throw new ToolCallException(implode('; ', $messages), retryable: false);
        }

        return $this->buildPayload($dto, $arguments);
    }

    /**
     * @param array<string, mixed> $arguments Raw arguments (needed for default presence check)
     *
     * @return array<string, mixed>
     */
    private function buildPayload(AskHumanArgumentsDTO $dto, array $arguments): array
    {
        $prompt = $this->resolvePrompt($dto);
        $questionId = $this->resolveQuestionId($dto, $arguments, $prompt);
        $schema = $this->resolveSchema($dto);
        $choices = $this->normalizeChoices($dto);
        $kind = $this->resolveKind($dto, $schema, $choices);

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

        // Use the raw arguments to detect if 'default' was provided, since
        // the DTO's mixed type cannot distinguish null-as-value from absent.
        if (\array_key_exists('default', $arguments)) {
            $payload['default'] = $dto->default;
        }

        if (null !== $dto->allowOther) {
            $payload['allow_other'] = $dto->allowOther;
        }

        if (null !== $dto->secret) {
            $payload['secret'] = $dto->secret;
        }

        return $payload;
    }

    private function resolvePrompt(AskHumanArgumentsDTO $dto): string
    {
        if (null !== $dto->prompt && '' !== $dto->prompt) {
            return $dto->prompt;
        }

        if ('' !== $dto->question) {
            return $dto->question;
        }

        return 'Please provide input.';
    }

    /**
     * Generate a stable question_id when one is not explicitly provided.
     *
     * The hash includes prompt, schema, kind, choices, and header so that
     * semantically identical questions (even across retries) resolve to the
     * same question_id. Explicit question_id always wins.
     *
     * @param array<string, mixed> $arguments Raw arguments
     */
    private function resolveQuestionId(AskHumanArgumentsDTO $dto, array $arguments, string $prompt): string
    {
        if (null !== $dto->questionId && '' !== $dto->questionId) {
            return $dto->questionId;
        }

        $hashInput = $prompt;

        if (null !== $dto->schema) {
            $encoded = json_encode($dto->schema, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $hashInput .= \is_string($encoded) ? $encoded : '';
        }

        $kind = $dto->kind ?? $dto->uiKind ?? null;
        if (null !== $kind) {
            $hashInput .= '/kind:'.$kind;
        }

        $choices = $arguments['choices'] ?? null;
        if (\is_array($choices) && [] !== $choices) {
            $encoded = json_encode($choices, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $hashInput .= '/choices:'.(\is_string($encoded) ? $encoded : '');
        }

        if (null !== $dto->header && '' !== $dto->header) {
            $hashInput .= '/header:'.$dto->header;
        }

        return 'ah_'.substr(hash('sha256', $hashInput), 0, 24);
    }

    /**
     * Resolve the answer schema from explicit schema, kind, or choices.
     *
     * @return array<string, mixed>
     */
    private function resolveSchema(AskHumanArgumentsDTO $dto): array
    {
        if (null !== $dto->schema) {
            return $dto->schema;
        }

        $kind = $dto->kind ?? $dto->uiKind ?? null;

        if ('confirm' === $kind || 'approval' === $kind) {
            return ['type' => 'boolean'];
        }

        $choices = $dto->choices ?? [];
        if ([] !== $choices) {
            $enumValues = self::extractEnumValues($choices);

            return [] !== $enumValues
                ? ['type' => 'string', 'enum' => $enumValues]
                : ['type' => 'string'];
        }

        return ['type' => 'string'];
    }

    /**
     * Extract enum string values from a choices array.
     *
     * @param array<mixed> $choices
     *
     * @return list<string>
     */
    private static function extractEnumValues(array $choices): array
    {
        $enum = [];
        foreach ($choices as $choice) {
            if (\is_string($choice)) {
                $enum[] = $choice;
            } elseif (\is_array($choice) && isset($choice['value']) && \is_string($choice['value'])) {
                $enum[] = $choice['value'];
            } elseif (\is_array($choice) && isset($choice['label']) && \is_string($choice['label'])) {
                $enum[] = $choice['label'];
            }
        }

        return $enum;
    }

    /**
     * Resolve the UI kind from explicit kind/ui_kind, schema, or choices.
     *
     * @param array<string, mixed>                                             $schema
     * @param list<array{label: string, description?: string, value?: string}> $choices
     */
    private function resolveKind(AskHumanArgumentsDTO $dto, array $schema, array $choices): string
    {
        $explicit = $dto->kind ?? $dto->uiKind ?? null;
        if (null !== $explicit && '' !== $explicit) {
            return $explicit;
        }

        if (isset($schema['type']) && 'boolean' === $schema['type']) {
            return 'confirm';
        }

        if ([] !== $choices) {
            return 'choice';
        }

        return 'text';
    }

    /**
     * Normalize choices: bare strings become {label, description, ?value} objects.
     *
     * Every normalized entry includes description (empty string when absent).
     *
     * @return list<array{label: string, description: string, value?: string}>
     */
    private function normalizeChoices(AskHumanArgumentsDTO $dto): array
    {
        $raw = $dto->choices ?? [];
        if ([] === $raw) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $item) {
            if (\is_string($item)) {
                $normalized[] = ['label' => $item, 'description' => ''];
            } elseif (\is_array($item)) {
                $entry = [];
                if (isset($item['label']) && \is_string($item['label'])) {
                    $entry['label'] = $item['label'];
                } elseif (isset($item['value']) && \is_string($item['value'])) {
                    $entry['label'] = $item['value'];
                } else {
                    continue;
                }

                // Always include description (empty string when absent)
                $entry['description'] = '';
                if (isset($item['description']) && \is_string($item['description']) && '' !== $item['description']) {
                    $entry['description'] = $item['description'];
                }

                if (isset($item['value']) && \is_string($item['value'])) {
                    $entry['value'] = $item['value'];
                }

                $normalized[] = $entry;
            }
        }

        return $normalized;
    }
}
