<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Optional embedding_api block for guided OM semantic settings.
 *
 * Blank base_url and model_id together mean semantic search stays off.
 */
final readonly class ObservationalMemorySemanticEmbeddingDTO
{
    public function __construct(
        #[SerializedName('base_url')]
        #[Assert\Type('string')]
        #[Assert\When(
            expression: 'this.modelId !== ""',
            constraints: [
                new Assert\NotBlank(message: 'semantic.embedding_api requires both base_url and model_id, or leave both blank to keep exact search.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.baseUrl !== ""',
            constraints: [
                new Assert\Regex(
                    pattern: '~^https?://(?:\[[0-9a-fA-F:]+\]|[^/?#@:]+)(?::\d+)?(?:/[^?#]*)?$~',
                    message: 'semantic.embedding_api.base_url must be an HTTP(S) URL without credentials, query, or fragment.',
                ),
            ],
        )]
        public string $baseUrl = '',
        #[SerializedName('model_id')]
        #[Assert\Type('string')]
        #[Assert\When(
            expression: 'this.baseUrl !== ""',
            constraints: [
                new Assert\NotBlank(message: 'semantic.embedding_api requires both base_url and model_id, or leave both blank to keep exact search.'),
            ],
        )]
        public string $modelId = '',
        #[SerializedName('query_prefix')]
        #[Assert\Type('string')]
        public string $queryPrefix = '',
        #[SerializedName('chunk_bytes')]
        #[Assert\GreaterThanOrEqual(
            value: 4,
            message: 'semantic.embedding_api.chunk_bytes must be at least 4.',
        )]
        public int $chunkBytes = 1200,
        #[SerializedName('overlap_bytes')]
        #[Assert\GreaterThanOrEqual(
            value: 0,
            message: 'semantic.embedding_api.overlap_bytes must be nonnegative.',
        )]
        #[Assert\Expression(
            'this.overlapBytes < this.chunkBytes',
            message: 'semantic.embedding_api.overlap_bytes must be smaller than chunk_bytes.',
        )]
        public int $overlapBytes = 192,
        #[SerializedName('max_lines')]
        #[Assert\Positive]
        public int $maxLines = 80,
        #[SerializedName('batch_size')]
        #[Assert\Positive]
        public int $batchSize = 4,
    ) {
    }
}
