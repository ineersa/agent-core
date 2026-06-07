<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Normalizes a MessageBag into the OpenAI Responses API format for Codex.
 *
 * Produces:
 *  - input: non-system messages (user, assistant, tool)
 *  - instructions: system message content extracted to top-level
 *
 * Designed to match the OpenResponses bridge pattern so Codex receives
 * the same Responses API request shape that OpenResponses produces.
 */
final class CodexMessageBagNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array{
     *     input: list<array<string, mixed>>,
     *     instructions?: string,
     * }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $messages = [];
        $messages['input'] = [];

        foreach ($data->withoutSystemMessage()->getMessages() as $message) {
            $normalized = $this->normalizer->normalize($message, $format, $context);

            // Assistant messages with tool calls return a list of function_call items.
            if ($message instanceof AssistantMessage && $message->hasToolCalls()) {
                $messages['input'] = array_merge($messages['input'], $normalized);
                continue;
            }

            $messages['input'][] = $normalized;
        }

        if (null !== $data->getSystemMessage()) {
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
