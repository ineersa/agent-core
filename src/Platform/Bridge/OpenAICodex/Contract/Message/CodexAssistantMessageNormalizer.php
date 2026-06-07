<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
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
     * @return array<string, mixed>|list<array{call_id: string, name: string, arguments: string, type: 'function_call'}>
     *                                                                                                                   A single array when no tool calls, a list when tool calls are present
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if ($data->hasToolCalls()) {
            return $this->normalizer->normalize($data->getToolCalls(), $format, $context);
        }

        $text = '';
        foreach ($data->getContent() as $part) {
            if ($part instanceof Text) {
                $text .= $part->getText();
            }
        }

        return [
            'role' => $data->getRole()->value,
            'type' => 'message',
            'content' => '' === $text ? null : [
                ['type' => 'output_text', 'text' => $text],
            ],
        ];
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
