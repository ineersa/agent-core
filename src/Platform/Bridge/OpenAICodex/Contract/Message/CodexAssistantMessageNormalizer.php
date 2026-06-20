<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Normalizes AssistantMessages into the Codex Responses API format.
 *
 * Without tool calls: returns {role, type: 'message', content: [{type: 'output_text', text}]}.
 * With tool calls: returns a list of {call_id, name, arguments, type: 'function_call'}.
 *
 * Uses typed output_text format matching Pi's /codex/responses shape.
 */
final class CodexAssistantMessageNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     *                                                         Single associative array for a message item when only text is present.
     *                                                         List of arrays when both reasoning and message items are needed.
     *                                                         Empty list when there is nothing to emit (thinking-only with no signature,
     *                                                         or a completely empty assistant message).
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if ($data->hasToolCalls()) {
            return $this->normalizer->normalize($data->getToolCalls(), $format, $context);
        }

        $text = '';
        $thinkingSignature = null;

        foreach ($data->getContent() as $part) {
            if ($part instanceof Text) {
                $text .= $part->getText();
            }

            if ($part instanceof Thinking) {
                $sig = $part->getSignature();
                if (\is_string($sig) && '' !== $sig) {
                    $thinkingSignature = $sig;
                }
            }
        }

        $output = [];

        // If there is a thinking signature, emit a separate reasoning input item
        // carrying the full reasoning item JSON (encrypted_content). This is the
        // pi-mono pattern: reasoning is a separate top-level input item, not
        // bundled into the message content.
        if (\is_string($thinkingSignature) && '' !== $thinkingSignature) {
            $output[] = json_decode($thinkingSignature, true, flags: \JSON_THROW_ON_ERROR);
        }

        // Emit a message item only if there is actual text content.
        // When there is no text and no thinking signature, return empty array
        // so the CodexMessageBagNormalizer skips this turn entirely (matching
        // pi-mono: `if (output.length === 0) continue;`).
        if ('' !== $text) {
            $output[] = [
                'role' => $data->getRole()->value,
                'type' => 'message',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ];
        }

        // Return empty array when there is nothing to emit (no text, no signature).
        // The CodexMessageBagNormalizer checks for empty arrays and skips them.
        if ([] === $output) {
            return [];
        }

        // Single item: return as-is so the normalizer can append it directly.
        // Multiple items: return as a list so CodexMessageBagNormalizer flattens them.
        return 1 === \count($output) ? $output[0] : $output;
    }

    protected function supportedDataClass(): string
    {
        return AssistantMessage::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
