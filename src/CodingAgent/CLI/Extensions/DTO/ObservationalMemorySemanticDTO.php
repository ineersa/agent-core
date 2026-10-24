<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Optional semantic hybrid-retrieval settings for guided OM setup.
 */
#[Assert\Expression(
    expression: '(this.rerankerApi.baseUrl === "" and this.rerankerApi.modelId === "") or (this.embeddingApi.baseUrl !== "" and this.embeddingApi.modelId !== "")',
    message: 'semantic.reranker_api requires semantic.embedding_api to be configured.',
)]
final readonly class ObservationalMemorySemanticDTO
{
    public function __construct(
        #[SerializedName('embedding_api')]
        #[Assert\Valid]
        public ObservationalMemorySemanticEmbeddingDTO $embeddingApi = new ObservationalMemorySemanticEmbeddingDTO(),
        #[SerializedName('reranker_api')]
        #[Assert\Valid]
        public ObservationalMemorySemanticRerankerDTO $rerankerApi = new ObservationalMemorySemanticRerankerDTO(),
    ) {
    }
}
