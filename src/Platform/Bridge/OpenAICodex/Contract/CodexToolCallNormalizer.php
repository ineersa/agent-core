<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Normalizes ToolCall instances into Codex Responses API format.
 *
 * Produces flat {call_id, name, arguments, type: 'function_call'} entries
 * for tool calls embedded in assistant messages within the input array.
 *
 * This differs from the default Symfony AI ToolCallNormalizer
 * which nests under {id, type: 'function', function: {name, arguments}}.
 */
final class CodexToolCallNormalizer extends ModelContractNormalizer
{
    /**
     * @return array{
     *     id: string,
     *     call_id: string,
     *     name: string,
     *     arguments: string,
     *     type: 'function_call'
     * }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return [
            'id' => $data->getId(),
            'call_id' => $data->getId(),
            'name' => $data->getName(),
            'arguments' => json_encode($data->getArguments() ?? new \stdClass()),
            'type' => 'function_call',
        ];
    }

    protected function supportedDataClass(): string
    {
        return ToolCall::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
