<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolResultTest extends TestCase
{
    #[Test]
    public function encodesDetailsAsToonWhilePreservingHumanText(): void
    {
        // Thesis: without this test, tool details could silently stay as PHP arrays
        // or corrupt content[].text while claiming TOON structured output.
        $text = "Moved task to DONE/example.md.\nSummary: ok";
        $result = ToolResult::text($text, [
            'from' => 'CODE-REVIEW',
            'to' => 'DONE',
            'notes' => ['Merged branch', 'Removed worktree'],
        ]);

        $this->assertSame($text, $result['content'][0]['text']);
        $this->assertIsString($result['details']);

        $decoded = Toon::decode($result['details']);
        $this->assertIsArray($decoded);
        $this->assertSame('CODE-REVIEW', $decoded['from'] ?? null);
        $this->assertSame('DONE', $decoded['to'] ?? null);
        $this->assertSame(['Merged branch', 'Removed worktree'], $decoded['notes'] ?? null);
    }
}
