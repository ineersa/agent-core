<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\CreateTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ListTasksHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\TaskListFormatter;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\UpdateTaskHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral proof that Hatfield task-workflow handlers return top-level TOON strings.
 *
 * Generic ToolExecutor only passes strings through unchanged; arrays are JSON-encoded.
 * These tests invoke real handlers and assert the runtime-facing return value.
 */
final class TaskWorkflowHandlerToonOutputTest extends TestCase
{
    private string $repoRoot;
    private string $boardRoot;

    protected function setUp(): void
    {
        $this->repoRoot = TestDirectoryIsolation::createProjectTempDir('tw-toon-repo');
        $this->boardRoot = TestDirectoryIsolation::createProjectTempDir('tw-toon-board');
        foreach (TaskStatusEnum::all() as $status) {
            mkdir($this->boardRoot.'/'.$status->value, 0o755, true);
        }
        putenv('HATFIELD_TASK_WORKFLOW_ROOT='.$this->boardRoot);
    }

    protected function tearDown(): void
    {
        putenv('HATFIELD_TASK_WORKFLOW_ROOT');
        TestDirectoryIsolation::removeDirectory($this->boardRoot);
        TestDirectoryIsolation::removeDirectory($this->repoRoot);
    }

    #[Test]
    public function listTasksReturnsTopLevelToonStringNotJsonEnvelope(): void
    {
        // Thesis: without this test, list_tasks could keep returning a content/details
        // array that ToolExecutor JSON-encodes, so Hatfield still shows JSON.
        file_put_contents(
            $this->boardRoot.'/TODO/demo.md',
            TaskMarkdown::renderTask('Demo task'),
        );

        $store = $this->store();
        $handler = new ListTasksHandler($store, new TaskListFormatter($store));
        $result = ($handler)([]);

        $decoded = $this->assertTopLevelToon($result);
        $this->assertIsString($decoded['message'] ?? null);
        $this->assertStringContainsString('TODO/demo.md', (string) $decoded['message']);
        $this->assertFalse($decoded['include_archive'] ?? true);
        $this->assertIsArray($decoded['tasks'] ?? null);
        $this->assertCount(1, $decoded['tasks']);
        $this->assertSame('TODO', $decoded['tasks'][0]['status'] ?? null);
        $this->assertSame('demo.md', $decoded['tasks'][0]['file'] ?? null);
        $this->assertSame('Demo task', $decoded['tasks'][0]['title'] ?? null);
    }

    #[Test]
    public function createTaskReturnsTopLevelToonWithPath(): void
    {
        // Thesis: without this test, create_task mutation output could still be a
        // JSON envelope and lose the structured path field outside nested details.
        $store = $this->store();
        $handler = new CreateTaskHandler($store);

        $result = ($handler)([
            'title' => 'Created via toon test',
            'id' => '2026-01-01-created-via-toon-test',
        ]);

        $decoded = $this->assertTopLevelToon($result);
        $this->assertStringContainsString('Created TODO/2026-01-01-created-via-toon-test.md', (string) $decoded['message']);
        $this->assertSame(
            $this->boardRoot.'/TODO/2026-01-01-created-via-toon-test.md',
            $decoded['path'] ?? null,
        );
        $this->assertFileExists($this->boardRoot.'/TODO/2026-01-01-created-via-toon-test.md');
    }

    #[Test]
    public function updateTaskNoOpReturnsTopLevelToonWithNormalizedTask(): void
    {
        // Thesis: without this test, the no-op update path (TaskInfo in details)
        // could reintroduce object encoding or content/details arrays.
        file_put_contents(
            $this->boardRoot.'/TODO/noop.md',
            TaskMarkdown::renderTask('Noop task'),
        );

        $store = $this->store();
        $handler = new UpdateTaskHandler($store);
        $result = ($handler)(['task' => 'noop']);

        $decoded = $this->assertTopLevelToon($result);
        $this->assertSame('No updates to apply (no fields provided).', $decoded['message'] ?? null);
        $this->assertIsArray($decoded['task'] ?? null);
        $this->assertSame('TODO', $decoded['task']['status'] ?? null);
        $this->assertSame('noop.md', $decoded['task']['file'] ?? null);
        $this->assertSame('Noop task', $decoded['task']['title'] ?? null);
        $this->assertSame($this->boardRoot.'/TODO/noop.md', $decoded['task']['path'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertTopLevelToon(mixed $result): array
    {
        $this->assertIsString($result, 'Handler must return a top-level string for ToolExecutor passthrough');
        $this->assertStringNotContainsString('"content"', $result);
        $this->assertStringNotContainsString('"details"', $result);
        $this->assertNull(json_decode($result, true), 'Handler output must not be a JSON object envelope');

        $decoded = Toon::decode($result);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('message', $decoded);

        return $decoded;
    }

    private function store(): TaskBoardStore
    {
        return new TaskBoardStore(
            $this->repoRoot,
            new TaskWorkflowSettings(taskRoot: $this->boardRoot),
        );
    }
}
