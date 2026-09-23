<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SemanticApiClient
{
    private HttpClientInterface $http;

    public function __construct(private SemanticSettings $settings, ?HttpClientInterface $http = null)
    {
        $this->http = $http ?? HttpClient::create(['timeout' => 10, 'max_duration' => 10]);
    }

    /** @param list<string> $texts
     * @return list<Vector>
     */
    public function embed(array $texts, bool $query = false): array
    {
        try {
            return $this->embedDocuments($texts, $query);
        } catch (\Throwable) {
            // Do not chain provider exceptions: HTTP response bodies can contain
            // the submitted memories. Propagate a visible, stage-specific failure.
            throw new SemanticSearchException('embedding_unavailable', 'Embedding request failed or returned invalid vectors.');
        }
    }

    /** @param list<string> $documents
     * @param \Closure(): void $checkpoint
     *
     * @return list<int> original document offsets, best first
     */
    public function rerank(string $query, array $documents, \Closure $checkpoint): array
    {
        try {
            return $this->rankDocuments($query, $documents, $checkpoint);
        } catch (SearchInterruptedException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new SemanticSearchException('reranker_unavailable', 'Configured reranker failed or returned invalid scores; no results were returned.');
        }
    }

    /** @param list<string> $texts
     * @return list<Vector>
     */
    private function embedDocuments(array $texts, bool $query): array
    {
        if ($query && '' !== $this->settings->queryPrefix) {
            $texts = array_map(fn (string $text): string => $this->settings->queryPrefix.' '.$text, $texts);
        }
        $provider = Factory::createProvider(
            baseUrl: $this->settings->embeddingUrl,
            httpClient: $this->http,
            supportsCompletions: false,
            embeddingsPath: '/embeddings',
        );
        $result = $provider->invoke(new EmbeddingsModel($this->settings->embeddingModel), $texts)->getResult();
        if (!$result instanceof VectorResult || \count($result->getContent()) !== \count($texts)) {
            throw new \RuntimeException('Embedding endpoint returned an invalid vector count.');
        }
        $vectors = array_values($result->getContent());
        foreach ($vectors as $vector) {
            $values = $vector->getData();
            if ([] === $values || \count($values) !== $vectors[0]->getDimensions()) {
                throw new \RuntimeException('Embedding endpoint returned inconsistent dimensions.');
            }
            $norm = 0.0;
            foreach ($values as $value) {
                if (!is_finite($value)) {
                    throw new \RuntimeException('Embedding endpoint returned a non-finite vector.');
                }
                $norm += $value * $value;
            }
            if (0.0 === $norm || !is_finite($norm)) {
                throw new \RuntimeException('Embedding endpoint returned an invalid vector.');
            }
        }

        return $vectors;
    }

    /** @param list<string> $documents
     * @param \Closure(): void $checkpoint
     *
     * @return list<int>
     */
    private function rankDocuments(string $query, array $documents, \Closure $checkpoint): array
    {
        $scores = [];
        foreach (array_chunk($documents, $this->settings->rerankerBatchSize, true) as $batch) {
            $checkpoint();
            $response = $this->http->request('POST', $this->settings->rerankerUrl.'/rerank', ['json' => [
                'model' => $this->settings->rerankerModel,
                'query' => $query,
                'documents' => array_values(array_map(fn (string $text): string => mb_substr($text, 0, $this->settings->rerankerDocumentCharacters, 'UTF-8'), $batch)),
                'top_n' => \count($batch),
            ]])->toArray();
            $entries = $response['results'] ?? null;
            if (!\is_array($entries) || \count($entries) !== \count($batch)) {
                throw new \RuntimeException('Reranker returned an invalid result count.');
            }
            $keys = array_keys($batch);
            $seen = [];
            foreach ($entries as $entry) {
                $index = \is_array($entry) ? ($entry['index'] ?? null) : null;
                $score = \is_array($entry) ? ($entry['relevance_score'] ?? null) : null;
                if (!\is_int($index) || !isset($keys[$index]) || isset($seen[$index]) || (!\is_float($score) && !\is_int($score)) || !is_finite((float) $score)) {
                    throw new \RuntimeException('Reranker returned invalid document scores.');
                }
                $scores[$keys[$index]] = (float) $score;
                $seen[$index] = true;
            }
            $checkpoint();
        }
        // Stable ties retain the input RRF order, independent of response order.
        // Filter only after validating every complete response, including rejected
        // entries. A high floor must never conceal malformed provider results.
        if (null !== $this->settings->rerankerMinScore) {
            $scores = array_filter($scores, fn (float $score): bool => $score >= $this->settings->rerankerMinScore);
        }
        ksort($scores);
        arsort($scores, \SORT_NUMERIC);

        return array_keys($scores);
    }
}
