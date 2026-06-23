<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Store;

use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;

final class TaskBoardStore
{
    public function __construct(
        private readonly string $codeRoot,
        private readonly TaskWorkflowSettings $config,
    ) {
    }

    public function resolveTaskRoot(): string
    {
        $envRoot = getenv('HATFIELD_TASK_WORKFLOW_ROOT');
        if (\is_string($envRoot) && '' !== $envRoot) {
            return $envRoot;
        }

        if (null !== $this->config->taskRoot && '' !== $this->config->taskRoot) {
            return $this->config->taskRoot;
        }

        $parentDir = \dirname($this->codeRoot);
        $basename = ('' !== basename($this->codeRoot) ? basename($this->codeRoot) : 'agent-core');
        $sibling = $parentDir.'/'.$basename.'-tasks';
        if (is_dir($sibling) && $this->isValidTaskRoot($sibling)) {
            return $sibling;
        }

        throw new \RuntimeException('No task board root configured. Set HATFIELD_TASK_WORKFLOW_ROOT env var, add extensions.settings.task_workflow.task_root in Hatfield settings, or create the external sibling board at: '.$sibling);
    }

    public function isValidTaskRoot(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        foreach (TaskStatusEnum::all() as $status) {
            if (is_dir($dir.'/'.$status->value)) {
                return true;
            }
        }

        return false;
    }

    public function rel(string $root, string $path): string
    {
        $rel = str_replace('\\', '/', substr($path, \strlen(rtrim($root, '/')) + 1));

        return '' === $rel ? '.' : $rel;
    }

    public function ensureTaskDirs(string $root): void
    {
        foreach (TaskStatusEnum::all() as $status) {
            $dir = $root.'/'.$status->value;
            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
            $keep = $dir.'/.gitkeep';
            if (!is_file($keep)) {
                file_put_contents($keep, '');
            }
        }
    }

    /**
     * @return list<TaskInfo>
     */
    public function listTasks(string $root, ?TaskStatusEnum $status = null): array
    {
        $this->ensureTaskDirs($root);
        $statuses = null !== $status ? [$status] : TaskStatusEnum::all();
        $tasks = [];
        foreach ($statuses as $s) {
            $dir = $root.'/'.$s->value;
            if (!is_dir($dir)) {
                continue;
            }
            $files = scandir($dir);
            if (false === $files) {
                continue;
            }
            $mdFiles = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.md')));
            sort($mdFiles);
            foreach ($mdFiles as $file) {
                $path = $dir.'/'.$file;
                $text = file_get_contents($path);
                if (false === $text) {
                    continue;
                }
                $tasks[] = new TaskInfo(
                    status: $s,
                    file: $file,
                    path: $path,
                    title: TaskMarkdown::extractTitle($text, $file),
                    branch: TaskMarkdown::extractField($text, 'Branch'),
                    worktree: TaskMarkdown::extractField($text, 'Worktree'),
                    prUrl: TaskMarkdown::extractField($text, 'PR URL'),
                );
            }
        }

        return $tasks;
    }

    public function findTask(string $root, string $query, ?TaskStatusEnum $status = null): TaskInfo
    {
        $normalized = preg_replace('/^@/', '', $query) ?? $query;
        $normalized = preg_replace('/\.md$/', '', $normalized) ?? $normalized;
        $candidates = $this->listTasks($root, $status);
        $matches = array_values(array_filter($candidates, static function (TaskInfo $task) use ($query, $normalized): bool {
            $stem = preg_replace('/\.md$/', '', $task->file) ?? $task->file;

            return $task->file === $query
                || $stem === $normalized
                || str_contains($stem, $normalized)
                || str_contains(strtolower($task->title), strtolower($normalized));
        }));
        if ([] === $matches) {
            throw new \RuntimeException('No task matched "'.$query.'"'.(null !== $status ? ' in '.$status->value : '').'.');
        }
        if (\count($matches) > 1) {
            $lines = array_map(static fn (TaskInfo $t): string => '- '.$t->status->value.'/'.$t->file, $matches);

            throw new \RuntimeException("Task query \"{$query}\" is ambiguous:\n".implode("\n", $lines));
        }

        return $matches[0];
    }

    public function moveFileWithMetadata(TaskInfo $task, TaskStatusEnum $to, string $text, string $taskRoot): string
    {
        $target = $taskRoot.'/'.$to->value.'/'.$task->file;
        if (is_file($target)) {
            throw new \RuntimeException('Target task already exists: '.$this->rel($taskRoot, $target));
        }
        $toDir = $taskRoot.'/'.$to->value;
        if (!is_dir($toDir)) {
            mkdir($toDir, 0o755, true);
        }
        if (false === file_put_contents($task->path, $text)) {
            throw new \RuntimeException('Failed to write task file: '.$task->path);
        }
        if (!rename($task->path, $target)) {
            throw new \RuntimeException('Failed to move task file to: '.$target);
        }

        return $target;
    }
}
