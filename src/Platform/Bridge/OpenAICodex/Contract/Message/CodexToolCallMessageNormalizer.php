<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Normalizes ToolCallMessage (tool results) into Codex Responses API format.
 *
 * Produces {type: 'function_call_output', call_id, output} for tool
 * results in the input array, matching the Responses API contract.
 *
 * This differs from the default Symfony AI ToolCallMessageNormalizer
 * which produces {role: 'tool', content, tool_call_id} for Chat Completions.
 */
final class CodexToolCallMessageNormalizer extends ModelContractNormalizer
{
    use NormalizerAwareTrait;

    /**
     * @return array{
     *     type: 'function_call_output',
     *     call_id: string,
     *     output: string
     * }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return [
            'type' => 'function_call_output',
            'call_id' => $data->getToolCall()->getId(),
            'output' => $data->getContent(),
        ];
    }

    protected function supportedDataClass(): string
    {
        return ToolCallMessage::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
