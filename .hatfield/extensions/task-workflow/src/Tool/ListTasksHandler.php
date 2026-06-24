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
     *
     * @return array{content: list<array{type: string, text: string}>, details: array<string, mixed>}
     */
    public function __invoke(array $arguments): array
    {
        $taskRoot = $this->store->resolveTaskRoot();
        $status = null;
        if (isset($arguments['status']) && \is_string($arguments['status']) && '' !== $arguments['status']) {
            $status = TaskStatusEnum::fromMixed($arguments['status']);
        }
        $tasks = $this->store->listTasks($taskRoot, $status);
        $text = $this->formatter->format($taskRoot, $tasks);

        return ToolResult::text($text, ['tasks' => $tasks]);
    }
}
