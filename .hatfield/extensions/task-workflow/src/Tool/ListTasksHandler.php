<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;

final readonly class ListTasksHandler implements ExtensionToolHandlerInterface
{
    public function __construct(
        private TaskBoardStore $store,
        private TaskListFormatter $formatter,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): string
    {
        $taskRoot = $this->store->resolveTaskRoot();
        $status = null;
        if (isset($arguments['status']) && \is_string($arguments['status']) && '' !== $arguments['status']) {
            $status = TaskStatusEnum::fromMixed($arguments['status']);
        }
        $includeArchive = isset($arguments['include_archive']) && true === $arguments['include_archive'];
        $tasks = $this->store->listTasks($taskRoot, $status, $includeArchive);
        $text = $this->formatter->format($taskRoot, $tasks);

        return ToolResult::text($text, [
            'tasks' => array_map(
                static fn ($task): array => [
                    'status' => $task->status->value,
                    'file' => $task->file,
                    'path' => $task->path,
                    'title' => $task->title,
                    'branch' => $task->branch,
                    'worktree' => $task->worktree,
                    'prUrl' => $task->prUrl,
                ],
                $tasks,
            ),
            'include_archive' => $includeArchive,
        ]);
    }
}
