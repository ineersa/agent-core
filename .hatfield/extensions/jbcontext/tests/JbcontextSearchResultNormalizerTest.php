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

    #[Test]
    public function dropsPhpHeaderOnlyChunksAndKeepsMeaningfulHitsInOrder(): void
    {
        $hits = JbcontextSearchResultNormalizer::normalize([
            'type' => 'search_result',
            'results' => [
                $this->hit('src/A.php', 0.99, "<?php\n\ndeclare(strict_types=1);", 1),
                $this->hit('src/B.php', 0.95, "<?php\ndeclare(strict_types=1);\n\nnamespace App;", 1),
                $this->hit('src/C.php', 0.90, 'declare(strict_types=1);', 1),
                $this->hit('src/D.php', 0.85, "private function acquireSessionOwnerLock(): bool\n{\n    return true;\n}", 40),
                $this->hit('docs/x.md', 0.80, '# Sessions', 1),
            ],
        ]);

        $this->assertSame([
            [
                'path' => 'src/B.php',
                'start_line' => 1,
                'similarity' => 0.95,
                'content' => "<?php\ndeclare(strict_types=1);\n\nnamespace App;",
            ],
            [
                'path' => 'src/D.php',
                'start_line' => 40,
                'similarity' => 0.85,
                'content' => "private function acquireSessionOwnerLock(): bool\n{\n    return true;\n}",
            ],
            [
                'path' => 'docs/x.md',
                'start_line' => 1,
                'similarity' => 0.80,
                'content' => '# Sessions',
            ],
        ], $hits);
    }

    #[Test]
    public function allHeaderOnlyNoiseYieldsEmptyResults(): void
    {
        $this->assertSame([], JbcontextSearchResultNormalizer::normalize([
            'type' => 'search_result',
            'results' => [
                $this->hit('src/A.php', 0.99, "<?php\n\ndeclare(strict_types=1);", 1),
                $this->hit('src/B.php', 0.90, '<?php', 1),
                $this->hit('src/C.php', 0.80, 'declare(strict_types=1);', 1),
            ],
        ]));
    }

    /**
     * @return array{
     *     result: array{
     *         scoredText: array{similarity: float},
     *         sourcePosition: array{relativePath: string, startOffset: int, endOffset: int},
     *         indexItemType: string
     *     },
     *     content: string,
     *     contentStartLine: int
     * }
     */
    private function hit(string $path, float $similarity, string $content, int $startLine): array
    {
        return [
            'result' => [
                'scoredText' => ['similarity' => $similarity],
                'sourcePosition' => [
                    'relativePath' => $path,
                    'startOffset' => 0,
                    'endOffset' => 10,
                ],
                'indexItemType' => 'CHUNKS',
            ],
            'content' => $content,
            'contentStartLine' => $startLine,
        ];
    }
}
