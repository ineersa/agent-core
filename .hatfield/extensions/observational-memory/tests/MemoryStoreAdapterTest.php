<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Semantic\MemoryStoreAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;

final class MemoryStoreAdapterTest extends TestCase
{
    #[Test]
    public function datedOverfetchIsBoundedAndReportsExhaustionEvenWhenNoHitsSurvive(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->expects($this->once())->method('query')->with($this->isInstanceOf(VectorQuery::class), ['k' => 500])->willReturnCallback(static function (): iterable {
            for ($i = 0; $i < 500; ++$i) {
                yield new VectorDocument((string) $i, new Vector([1.0]));
            }
        });
        $adapter = new MemoryStoreAdapter($store, false, 1_000_000, static fn (): bool => false);
        $this->assertSame([], iterator_to_array($adapter->query(new VectorQuery(new Vector([1.0])), ['maxItems' => 100])));
        $this->assertTrue($adapter->wasTruncated());
    }

    #[Test]
    public function smallFilteredCorpusDoesNotClaimTruncation(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->expects($this->once())->method('query')->with($this->anything(), ['k' => 2])->willReturn([
            new VectorDocument('old', new Vector([1.0])), new VectorDocument('new', new Vector([1.0])),
        ]);
        $adapter = new MemoryStoreAdapter($store, false, 2, static fn (VectorDocument $hit): bool => 'new' === $hit->getId());
        $hits = iterator_to_array($adapter->query(new VectorQuery(new Vector([1.0]))));
        $this->assertCount(1, $hits);
        $this->assertSame('new', $hits[0]->getId());
        $this->assertFalse($adapter->wasTruncated());
    }
}
