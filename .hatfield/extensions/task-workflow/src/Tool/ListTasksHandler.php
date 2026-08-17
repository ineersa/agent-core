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
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): string
    {
        $status = null;
        if (isset($arguments['status']) && \is_string($arguments['status']) && '' !== $arguments['status']) {
            $status = TaskStatusEnum::fromMixed($arguments['status']);
        }
        $includeArchive = isset($arguments['include_archive']) && true === $arguments['include_archive'];
        $tasks = $this->store->listTasks($this->store->resolveTaskRoot(), $status, $includeArchive);

        return ToolResult::structured([
            'tasks' => $tasks,
            'include_archive' => $includeArchive,
        ]);
    }
}
