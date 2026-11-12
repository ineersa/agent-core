<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\HatfieldExt\TaskWorkflow\DTO\GitHubTaskDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

final class ToolResultTest extends TestCase
{
    public function testTopLevelToonPreservesCanonicalTaskIdentityAndEnum(): void
    {
        // Generic ToolExecutor passes strings through but JSON-encodes arrays;
        // this boundary must not reintroduce a content/details envelope.
        $task = new GitHubTaskDTO(7, 'https://github.com/acme/project/issues/7', 'Title', 'Current plan', 'closed', 'completed', '2026-01-01T00:00:00Z', 'acme/project', TaskStatusEnum::CODE_REVIEW);
        $result = ToolResult::text('Awaiting finalization', ['task' => $task]);
        $this->assertNull(json_decode($result, true));
        $decoded = Toon::decode($result);
        $this->assertSame('Awaiting finalization', $decoded['message']);
        $this->assertSame('CODE-REVIEW', $decoded['task']['status']);
        $this->assertSame(7, $decoded['task']['number']);
        $this->assertSame($task->url, $decoded['task']['url']);
        $this->assertArrayNotHasKey('file', $decoded['task']);
        $this->assertArrayNotHasKey('content', $decoded);
    }

    public function testStructuredListDoesNotDuplicateFormattedMessage(): void
    {
        $decoded = Toon::decode(ToolResult::structured(['tasks' => [['number' => 7, 'status' => 'TODO']], 'page' => 1]));
        $this->assertArrayNotHasKey('message', $decoded);
        $this->assertSame(7, $decoded['tasks'][0]['number']);
    }
}
