<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticApiClient;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSearchException;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SemanticApiClientTest extends TestCase
{
    #[Test]
    public function reranksAcrossBoundedBatchesAndTruncatesByCharacters(): void
    {
        $sizes = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sizes): MockResponse {
            self::assertSame('http://rank.test/v1/rerank', $url);
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('needle', $body['query']);
            $sizes[] = \count($body['documents']);
            $results = [];
            foreach ($body['documents'] as $i => $text) {
                self::assertSame(768, mb_strlen($text));
                self::assertTrue(mb_check_encoding($text, 'UTF-8'));
                $results[] = ['index' => $i, 'relevance_score' => (int) $text[0] / 10];
            }

            return new MockResponse(json_encode(['results' => array_reverse($results)], \JSON_THROW_ON_ERROR));
        });
        $settings = $this->settings();
        $documents = array_map(static fn (int $i): string => $i.str_repeat('é', 900), range(0, 9));
        $order = (new SemanticApiClient($settings, $http))->rerank('needle', $documents, static function (): void {});
        $this->assertSame([8, 2], $sizes);
        $this->assertSame(range(9, 0), $order);
    }

    #[Test]
    public function duplicateRerankerIndicesFailRatherThanReturningPartialRanking(): void
    {
        $http = new MockHttpClient(new MockResponse('{"results":[{"index":0,"relevance_score":0.9},{"index":0,"relevance_score":0.8}]}'));
        $this->expectException(SemanticSearchException::class);
        $this->expectExceptionMessage('Configured reranker failed');
        (new SemanticApiClient($this->settings(), $http))->rerank('query', ['a', 'b'], static function (): void {});
    }

    #[Test]
    public function configuredFloorFiltersRawScoresAcrossBatchesIncludingAnEmptyResult(): void
    {
        $responses = [
            new MockResponse('{"results":[{"index":2,"relevance_score":-4.01},{"index":0,"relevance_score":-3.9},{"index":1,"relevance_score":-4}]}'),
            new MockResponse('{"results":[{"index":1,"relevance_score":-6},{"index":0,"relevance_score":-3.9}]}'),
        ];
        $settings = SemanticSettings::fromArray([
            'embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed'],
            'reranker_api' => ['base_url' => 'http://rank.test/v1', 'model_id' => 'rank', 'batch_size' => 3, 'min_score' => -4],
        ]);
        $this->assertNotNull($settings);
        $client = new SemanticApiClient($settings, new MockHttpClient($responses));
        $this->assertSame([0, 3, 1], $client->rerank('query', ['a', 'b', 'c', 'd', 'e'], static function (): void {}));

        $none = new SemanticApiClient($settings, new MockHttpClient(new MockResponse('{"results":[{"index":0,"relevance_score":-4.01}]}')));
        $this->assertSame([], $none->rerank('query', ['a'], static function (): void {}));
    }

    #[Test]
    public function invalidScoresStillFailAboveTheConfiguredFloor(): void
    {
        $settings = SemanticSettings::fromArray([
            'embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed'],
            'reranker_api' => ['base_url' => 'http://rank.test/v1', 'model_id' => 'rank', 'min_score' => 10],
        ]);
        $this->assertNotNull($settings);
        $client = new SemanticApiClient($settings, new MockHttpClient(new MockResponse('{"results":[{"index":0,"relevance_score":"bad"}]}')));
        $this->expectException(SemanticSearchException::class);
        $client->rerank('query', ['a'], static function (): void {});
    }

    private function settings(): SemanticSettings
    {
        $settings = SemanticSettings::fromArray([
            'embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed'],
            'reranker_api' => ['base_url' => 'http://rank.test/v1', 'model_id' => 'rank'],
        ]);
        $this->assertNotNull($settings);

        return $settings;
    }
}
