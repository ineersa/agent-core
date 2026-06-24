<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;

final class TaskListFormatter
{
    public function __construct(
        private readonly TaskBoardStore $store,
    ) {
    }

    /**
     * @param list<TaskInfo> $tasks
     */
    public function format(string $taskRoot, array $tasks): string
    {
        if ([] === $tasks) {
            return 'No tasks.';
        }

        $lines = [];
        foreach ($tasks as $task) {
            $extra = [];
            if (null !== $task->branch && '' !== $task->branch) {
                $extra[] = $task->branch;
            }
            if (null !== $task->prUrl && '' !== $task->prUrl) {
                $extra[] = 'PR: '.$task->prUrl;
            }
            $extras = [] !== $extra ? ' ['.implode(' ', $extra).']' : '';

            $lines[] = '- '.$task->status->value.'/'.$task->file.': '.$task->title.$extras.' ('.$this->store->rel($taskRoot, $task->path).')';
        }

        return implode("\n", $lines);
    }
}
