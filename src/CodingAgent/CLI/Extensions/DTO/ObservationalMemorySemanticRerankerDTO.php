<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Ineersa\CodingAgent\CLI\Extensions\Validation\FiniteNumber;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Optional reranker_api block for guided OM semantic settings.
 *
 * Blank base_url and model_id together omit reranking. min_score null means no floor.
 */
final readonly class ObservationalMemorySemanticRerankerDTO
{
    public function __construct(
        #[SerializedName('base_url')]
        #[Assert\Type('string')]
        #[Assert\When(
            expression: 'this.modelId !== ""',
            constraints: [
                new Assert\NotBlank(message: 'semantic.reranker_api requires both base_url and model_id, or leave both blank to skip reranking.'),
            ],
        )]
        #[Assert\When(
            expression: 'this.baseUrl !== ""',
            constraints: [
                new Assert\Regex(
                    pattern: '~^https?://(?:\[[0-9a-fA-F:]+\]|[^/?#@:]+)(?::\d+)?(?:/[^?#]*)?$~',
                    message: 'semantic.reranker_api.base_url must be an HTTP(S) URL without credentials, query, or fragment.',
                ),
            ],
        )]
        public string $baseUrl = '',
        #[SerializedName('model_id')]
        #[Assert\Type('string')]
        #[Assert\When(
            expression: 'this.baseUrl !== ""',
            constraints: [
                new Assert\NotBlank(message: 'semantic.reranker_api requires both base_url and model_id, or leave both blank to skip reranking.'),
            ],
        )]
        public string $modelId = '',
        #[SerializedName('batch_size')]
        #[Assert\Positive]
        public int $batchSize = 8,
        #[SerializedName('document_characters')]
        #[Assert\Positive]
        public int $documentCharacters = 768,
        #[SerializedName('min_score')]
        #[Assert\Type(type: ['float', 'int', 'null'])]
        #[FiniteNumber(message: 'semantic.reranker_api.min_score must be a finite number.')]
        public int|float|null $minScore = null,
    ) {
    }
}
