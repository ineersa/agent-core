<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SemanticSettingsTest extends TestCase
{
    #[Test]
    public function absentSettingsLeaveExactSearchEnabled(): void
    {
        $this->assertNull(SemanticSettings::fromArray(null));
        $this->assertNull(SemanticSettings::fromArray([]));
    }

    #[Test]
    public function scoreFloorIsOptionalAndDoesNotChangeStoredEmbeddingSignature(): void
    {
        $config = [
            'embedding_api' => ['base_url' => 'http://embed.test', 'model_id' => 'embed'],
            'reranker_api' => ['base_url' => 'http://rank.test', 'model_id' => 'rank'],
        ];
        $without = SemanticSettings::fromArray($config);
        $this->assertNotNull($without);
        $this->assertNull($without->rerankerMinScore);
        $config['reranker_api']['min_score'] = -4;
        $with = SemanticSettings::fromArray($config);
        $this->assertNotNull($with);
        $this->assertSame(-4.0, $with->rerankerMinScore);
        $this->assertSame($without->signature(), $with->signature());
    }

    #[Test]
    #[DataProvider('invalidConfiguration')]
    public function rejectsInvalidConfiguration(array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SemanticSettings::fromArray($config);
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'reranker without embedding' => [['reranker_api' => ['base_url' => 'http://rank.test', 'model_id' => 'rank']]];
        yield 'overlap prevents progress' => [['embedding_api' => ['base_url' => 'http://embed.test', 'model_id' => 'embed', 'chunk_bytes' => 10, 'overlap_bytes' => 10]]];
        yield 'no HTTP endpoint' => [['embedding_api' => ['base_url' => 'file:///tmp/private', 'model_id' => 'embed']]];
        yield 'credentials in endpoint' => [['embedding_api' => ['base_url' => 'http://user:password@embed.test', 'model_id' => 'embed']]];
        yield 'zero batch' => [['embedding_api' => ['base_url' => 'http://embed.test', 'model_id' => 'embed', 'batch_size' => 0]]];
        yield 'non-numeric floor' => [['embedding_api' => ['base_url' => 'http://embed.test', 'model_id' => 'embed'], 'reranker_api' => ['base_url' => 'http://rank.test', 'model_id' => 'rank', 'min_score' => '-4']]];
        yield 'infinite floor' => [['embedding_api' => ['base_url' => 'http://embed.test', 'model_id' => 'embed'], 'reranker_api' => ['base_url' => 'http://rank.test', 'model_id' => 'rank', 'min_score' => \INF]]];
    }
}
