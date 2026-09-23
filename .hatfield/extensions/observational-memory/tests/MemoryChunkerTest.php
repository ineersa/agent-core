<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Semantic\MemoryChunker;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MemoryChunkerTest extends TestCase
{
    #[Test]
    public function byteOverlapKeepsUtf8Whole(): void
    {
        $settings = SemanticSettings::fromArray(['embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed', 'chunk_bytes' => 10, 'overlap_bytes' => 3]]);
        $this->assertNotNull($settings);
        $chunker = new MemoryChunker($settings);
        $this->assertSame(['abcdefghij', 'hijklmnop'], $chunker->split('abcdefghijklmnop'));
        $this->assertSame(['😀abcdé', 'défgh😀', 'ij'], $chunker->split('😀abcdéfgh😀ij'));
    }

    #[Test]
    public function shortLinesStillAdvanceWhenOverlapExceedsTheLineLimitedChunk(): void
    {
        $settings = SemanticSettings::fromArray(['embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed', 'chunk_bytes' => 12, 'overlap_bytes' => 8, 'max_lines' => 2]]);
        $this->assertNotNull($settings);
        $chunks = (new MemoryChunker($settings))->split("a\nb\nc\nd");
        $this->assertSame(["a\nb\n", "\nb\n", "b\nc\n", "\nc\n", "c\nd"], $chunks);
    }
}
