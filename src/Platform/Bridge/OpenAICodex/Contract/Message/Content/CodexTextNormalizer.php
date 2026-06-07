<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\Content;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Model;

/**
 * Normalizes Text content into the Codex Responses API typed format.
 *
 * Produces {type: 'input_text', text: '...'} instead of a raw string,
 * matching the Responses API input item content shape.
 *
 * Following the OpenResponses bridge TextNormalizer pattern.
 */
final class CodexTextNormalizer extends ModelContractNormalizer
{
    /**
     * @return array{
     *     type: 'input_text',
     *     text: string
     * }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return [
            'type' => 'input_text',
            'text' => $data->getText(),
        ];
    }

    protected function supportedDataClass(): string
    {
        return Text::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
