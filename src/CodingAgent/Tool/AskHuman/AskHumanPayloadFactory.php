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
 * Validator for upfront validation. The answer schema is always derived
 * internally (boolean for kind=confirm, enum from non-empty choices, else string).
 * No raw JSON Schema is accepted as input. The output payload preserves UI
 * metadata (header, choices) alongside the core interrupt fields.
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

    /**
     * Always generate a stable output question_id from question content.
     *
     * The hash includes question, kind, choices, and header so that
     * semantically identical questions (even across retries) resolve to the
     * same question_id. Model-supplied IDs are not accepted.
     */
    private function generateQuestionId(AskHumanArgumentsDTO $dto): string
    {
        $hashInput = $dto->question;

        $kind = $dto->kind;
        if (null !== $kind) {
            $hashInput .= '/kind:'.$kind;
        }

        $choices = $dto->choices;
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
     * Resolve the answer schema from confirm kind or non-empty choices.
     *
     * The schema is always derived internally — no raw JSON Schema is
     * accepted as input to avoid LLM errors with embedded schema syntax.
     *
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
     * Resolve output ui_kind: confirm when requested, choice from non-empty
     * choices, otherwise text for free-form.
     *
     * @param list<array{label: string, description: string}> $choices
     */
    private function resolveKind(AskHumanArgumentsDTO $dto, array $choices): string
    {
        if ('confirm' === $dto->kind) {
            return 'confirm';
        }

        if ([] !== $choices) {
            return 'choice';
        }

        return 'text';
    }

    /**
     * Normalize choices: bare strings become {label, description} objects.
     *
     * Input is guaranteed by DTO validation to be list<non-empty-string>.
     * Every normalized entry includes description (empty string when absent).
     *
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
            // Validation guarantees non-empty strings
            $normalized[] = ['label' => $item, 'description' => ''];
        }

        return $normalized;
    }
}
