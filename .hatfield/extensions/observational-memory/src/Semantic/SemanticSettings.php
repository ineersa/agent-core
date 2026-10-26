<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

final readonly class SemanticSettings
{
    public const int DEFAULT_CHUNK_BYTES = 1200;
    public const int DEFAULT_OVERLAP_BYTES = 192;
    public const int DEFAULT_MAX_LINES = 80;
    public const int DEFAULT_EMBEDDING_BATCH_SIZE = 4;
    public const int DEFAULT_RERANKER_BATCH_SIZE = 8;
    public const int DEFAULT_DOCUMENT_CHARACTERS = 768;

    public function __construct(
        public string $embeddingUrl,
        public string $embeddingModel,
        public string $queryPrefix,
        public int $chunkBytes,
        public int $overlapBytes,
        public int $chunkLines,
        public int $embeddingBatchSize,
        public ?string $rerankerUrl,
        public ?string $rerankerModel,
        public int $rerankerBatchSize,
        public int $rerankerDocumentCharacters,
        public ?float $rerankerMinScore,
    ) {
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (null === $raw || [] === $raw) {
            return null;
        }
        if (!\is_array($raw) || !\is_array($raw['embedding_api'] ?? null)) {
            throw new \InvalidArgumentException('observational_memory.semantic requires embedding_api.');
        }
        $embedding = $raw['embedding_api'];
        $reranker = $raw['reranker_api'] ?? null;
        if (null !== $reranker && !\is_array($reranker)) {
            throw new \InvalidArgumentException('semantic.reranker_api must be a mapping.');
        }
        $minScore = null;
        if (null !== $reranker && \array_key_exists('min_score', $reranker)) {
            $value = $reranker['min_score'];
            if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException('semantic.reranker_api.min_score must be a finite number.');
            }
            $minScore = (float) $value;
        }
        $bytes = self::positive($embedding, 'chunk_bytes', self::DEFAULT_CHUNK_BYTES);
        $overlap = $embedding['overlap_bytes'] ?? self::DEFAULT_OVERLAP_BYTES;
        if (!\is_int($overlap) || $overlap < 0 || $overlap >= $bytes || $bytes < 4) {
            throw new \InvalidArgumentException('chunk_bytes must be at least 4; overlap_bytes must be nonnegative and smaller than chunk_bytes.');
        }

        return new self(
            self::url($embedding), self::text($embedding, 'model_id'),
            self::text($embedding, 'query_prefix', ''), $bytes, $overlap,
            self::positive($embedding, 'max_lines', self::DEFAULT_MAX_LINES),
            self::positive($embedding, 'batch_size', self::DEFAULT_EMBEDDING_BATCH_SIZE),
            null === $reranker ? null : self::url($reranker),
            null === $reranker ? null : self::text($reranker, 'model_id'),
            self::positive($reranker ?? [], 'batch_size', self::DEFAULT_RERANKER_BATCH_SIZE),
            self::positive($reranker ?? [], 'document_characters', self::DEFAULT_DOCUMENT_CHARACTERS),
            $minScore,
        );
    }

    public function signature(): string
    {
        // Query/reranker settings do not change stored document embeddings.
        return hash('sha256', json_encode([$this->embeddingUrl, $this->embeddingModel, $this->chunkBytes, $this->overlapBytes, $this->chunkLines, 1], \JSON_THROW_ON_ERROR));
    }

    /** @param array<array-key, mixed> $raw */
    private static function text(array $raw, string $key, ?string $default = null): string
    {
        $value = $raw[$key] ?? $default;
        if (!\is_string($value) || ('' === trim($value) && null === $default)) {
            throw new \InvalidArgumentException('semantic API requires a non-empty '.$key.'.');
        }

        return trim($value);
    }

    /** @param array<array-key, mixed> $raw */
    private static function url(array $raw): string
    {
        $url = self::text($raw, 'base_url');
        if (!\in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true) || !parse_url($url, \PHP_URL_HOST) || null !== parse_url($url, \PHP_URL_USER) || null !== parse_url($url, \PHP_URL_QUERY) || null !== parse_url($url, \PHP_URL_FRAGMENT)) {
            throw new \InvalidArgumentException('semantic base_url must be an HTTP(S) URL without credentials, query, or fragment.');
        }

        return rtrim($url, '/');
    }

    /** @param array<array-key, mixed> $raw */
    private static function positive(array $raw, string $key, int $default): int
    {
        $value = $raw[$key] ?? $default;
        if (!\is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('semantic '.$key.' must be a positive integer.');
        }

        return $value;
    }
}
