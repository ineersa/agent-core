<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\HatfieldExt\TaskWorkflow\DTO\GitHubTaskDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;

final class TaskListFormatter
{
    /**
     * @param list<GitHubTaskDTO>            $tasks
     * @param array<int,array<string,mixed>> $local
     */
    public function format(array $tasks, array $local = []): string
    {
        $lines = [];
        foreach ($tasks as $task) {
            $suffix = '';
            if ('CLOSED' === strtoupper($task->state) && \in_array($task->status, [TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::CODE_REVIEW], true)) {
                $suffix = ' — closed on GitHub, awaiting finalization';
            }
            $pending = $local[$task->number]['pending_operation'] ?? null;
            if (null !== $pending) {
                $suffix .= ' — local pending '.$pending;
            }
            $lines[] = '#'.$task->number.' ['.($task->status->value ?? 'unmanaged').'] '.$task->title.$suffix."\n".$task->url;
        }

        return [] === $lines ? 'No tasks on this page.' : implode("\n", $lines);
    }
}
