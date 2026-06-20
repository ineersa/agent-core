<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Codex-specific MessageBag normalizer.
 *
 * Extends the OpenResponses pattern with two additional behaviors:
 * 1. Flattens multi-item normalizer output (reasoning + message) into the
 *    input array, not as a nested array.
 * 2. Skips messages that normalize to an empty array (thinking-only messages
 *    with no signature produce no input items).
 *
 * This is separated from the OpenResponses MessageBagNormalizer because the
 * Codex contract must flatten reasoning items (separate top-level input items
 * emitted by CodexAssistantMessageNormalizer) rather than nesting them.
 */
final class CodexMessageBagNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param MessageBag $data
     *
     * @return array{
     *     input: array<string, mixed>,
     *     instructions?: string,
     * }
     *
     * @throws ExceptionInterface
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $messages['input'] = [];

        foreach ($data->withoutSystemMessage()->getMessages() as $message) {
            $normalized = $this->normalizer->normalize($message, $format, $context);

            // Skip empty results (thinking-only messages with no signature
            // return [] from CodexAssistantMessageNormalizer).
            if (\is_array($normalized) && [] === $normalized) {
                continue;
            }

            // Flatten when the normalized result is a sequential list (has
            // numeric index 0). This handles:
            //   - Tool-call assistant messages (return a list of function_call items)
            //   - Thinking+text assistant messages (return [reasoning_item, message_item])
            if (\is_array($normalized) && [] !== $normalized && isset($normalized[0])) {
                $messages['input'] = array_merge($messages['input'], $normalized);
            } elseif (\is_array($normalized)) {
                // Single associative item — append as-is (standard message shape)
                $messages['input'][] = $normalized;
            } else {
                // Unexpected type — append as-is (defensive)
                $messages['input'][] = $normalized;
            }
        }

        if ($data->getSystemMessage()) {
            $messages['instructions'] = $data->getSystemMessage()->getContent();
        }

        return $messages;
    }

    protected function supportedDataClass(): string
    {
        return MessageBag::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
