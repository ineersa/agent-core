<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextSearchResultNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextSearchResultNormalizerTest extends TestCase
{
    #[Test]
    public function normalizesVerifiedSearchHitShape(): void
    {
        $hits = JbcontextSearchResultNormalizer::normalize([
            'type' => 'search_result',
            'results' => [
                [
                    'result' => [
                        'scoredText' => ['similarity' => 0.99],
                        'sourcePosition' => [
                            'relativePath' => 'src/Example.php',
                            'startOffset' => 10,
                            'endOffset' => 20,
                        ],
                        'indexItemType' => 'CHUNKS',
                    ],
                    'content' => "class Example\n{\n}",
                    'contentStartLine' => 12,
                ],
            ],
            'revision' => 'abc',
        ]);

        $this->assertSame([
            [
                'path' => 'src/Example.php',
                'start_line' => 12,
                'similarity' => 0.99,
                'content' => "class Example\n{\n}",
            ],
        ], $hits);
    }

    #[Test]
    public function emptyResultsStayEmpty(): void
    {
        $this->assertSame([], JbcontextSearchResultNormalizer::normalize([
            'type' => 'search_result',
            'results' => [],
        ]));
    }
}
