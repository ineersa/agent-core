<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolResultTest extends TestCase
{
    #[Test]
    public function returnsTopLevelToonStringWithMessageAndStructuredFields(): void
    {
        // Thesis: without this test, ToolResult could silently return a Pi-shaped
        // content/details array that ToolExecutor JSON-encodes, so the model never
        // sees top-level TOON.
        $text = "Moved task to DONE/example.md.\nSummary: ok";
        $result = ToolResult::text($text, [
            'from' => 'CODE-REVIEW',
            'to' => 'DONE',
            'notes' => ['Merged branch', 'Removed worktree'],
        ]);

        $this->assertIsString($result);
        $this->assertStringNotContainsString('"content"', $result);
        $this->assertStringNotContainsString('"details"', $result);
        $this->assertNull(json_decode($result, true), 'Top-level result must not be a JSON envelope');

        $decoded = Toon::decode($result);
        $this->assertIsArray($decoded);
        $this->assertSame($text, $decoded['message'] ?? null);
        $this->assertSame('CODE-REVIEW', $decoded['from'] ?? null);
        $this->assertSame('DONE', $decoded['to'] ?? null);
        $this->assertSame(['Merged branch', 'Removed worktree'], $decoded['notes'] ?? null);
    }

    #[Test]
    public function normalizesTaskInfoObjectsAtTheExtensionBoundary(): void
    {
        // Thesis: without this test, handlers that pass TaskInfo objects into details
        // could lose status/file/path fields or encode opaque object noise.
        $task = new TaskInfo(
            status: TaskStatusEnum::TODO,
            file: 'example.md',
            path: '/tmp/board/TODO/example.md',
            title: 'Example',
            branch: 'task/example',
            worktree: null,
            prUrl: null,
        );

        $result = ToolResult::text('No updates to apply (no fields provided).', ['task' => $task]);
        $decoded = Toon::decode($result);

        $this->assertIsArray($decoded);
        $this->assertSame('No updates to apply (no fields provided).', $decoded['message'] ?? null);
        $this->assertSame([
            'status' => 'TODO',
            'file' => 'example.md',
            'path' => '/tmp/board/TODO/example.md',
            'title' => 'Example',
            'branch' => 'task/example',
            'worktree' => null,
            'prUrl' => null,
        ], $decoded['task'] ?? null);
    }
}
