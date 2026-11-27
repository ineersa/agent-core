<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Edit;

use Ineersa\CodingAgent\Tool\Edit\EditPatchApplicator;
use Ineersa\CodingAgent\Tool\Edit\EditPatchParser;
use Ineersa\CodingAgent\Tool\Edit\Exception\StalePatchHunkException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EditPatchApplicatorTest extends TestCase
{
    #[DataProvider('staleHunks')]
    public function testStaleHunkCarriesOneBasedFailedLine(string $original, string $patch, int $failedLine): void
    {
        $chunks = (new EditPatchParser())->parse($patch);

        try {
            (new EditPatchApplicator())->computeReplacements($chunks, $original);
            $this->fail('Expected StalePatchHunkException');
        } catch (StalePatchHunkException $e) {
            $this->assertSame($failedLine, $e->failedLine);
            $this->assertTrue($e->retryable());
            $this->assertStringContainsString('around line '.$failedLine.',', $e->hint());
        }
    }

    public static function staleHunks(): iterable
    {
        yield 'missing old block' => ["one\ntwo\n", "@@\n-missing\n+new", 1];
        yield 'missing seek hint' => ["one\ntwo\n", "@@ missing\n-one\n+ONE", 1];
        yield 'empty file' => ['', "@@\n-missing\n+new", 1];
        yield 'cursor after first hunk' => ["one\ntwo\nthree\n", "@@\n-one\n+ONE\n@@\n-missing\n+new", 2];
        yield 'EOF cursor clamped to last line' => ["one\ntwo\n", "@@\n-two\n+TWO\n@@ missing\n-one\n+ONE", 2];
        yield 'cursor after seek hint' => ["one\ntwo\nthree\n", "@@ two\n-missing\n+new", 2];
    }
}
