<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;

final readonly class UpdateTaskHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(
        private TaskBoardStore $store,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $control = InvocationControl::fromContext($context);
        if (null !== ($interrupt = $control->interrupted('Cancelled before update_task started.'))) {
            return $interrupt;
        }

        $taskQuery = $arguments['task'] ?? null;
        if (!\is_string($taskQuery) || '' === $taskQuery) {
            throw new \InvalidArgumentException('task is required');
        }

        $from = null;
        if (isset($arguments['from']) && \is_string($arguments['from']) && '' !== $arguments['from']) {
            $from = TaskStatusEnum::fromMixed($arguments['from']);
        }

        $taskRoot = $this->store->resolveTaskRoot();
        $this->store->ensureTaskDirs($taskRoot);
        $lock = new TaskBoardLock(TaskBoardLock::lockPathForRoot($taskRoot));

        $locked = $lock->withLock(
            function () use ($taskRoot, $taskQuery, $from, $arguments, $control): mixed {
                if (null !== ($interrupt = $control->interrupted('Cancelled before reading task file.'))) {
                    return $interrupt;
                }

                $task = $this->store->findTask($taskRoot, $taskQuery, $from);
                $text = file_get_contents($task->path);
                if (false === $text) {
                    throw new \RuntimeException('Failed to read task file: '.$task->path);
                }

                $notes = [];
                if (isset($arguments['forkRun']) && \is_string($arguments['forkRun']) && '' !== $arguments['forkRun']) {
                    $text = TaskMarkdown::updateField($text, 'Fork run', $arguments['forkRun']);
                    $notes[] = 'Recorded fork run: '.$arguments['forkRun'];
                }
                if (isset($arguments['prUrl']) && \is_string($arguments['prUrl']) && '' !== $arguments['prUrl']) {
                    $text = TaskMarkdown::updateField($text, 'PR URL', $arguments['prUrl']);
                    $notes[] = 'Updated PR URL: '.$arguments['prUrl'];
                }
                if (isset($arguments['prStatus']) && \is_string($arguments['prStatus']) && '' !== $arguments['prStatus']) {
                    $text = TaskMarkdown::updateField($text, 'PR Status', $arguments['prStatus']);
                    $notes[] = 'Updated PR Status: '.$arguments['prStatus'];
                }
                if (isset($arguments['validation']) && \is_array($arguments['validation'])) {
                    $vals = array_values(array_filter($arguments['validation'], is_string(...)));
                    if ([] !== $vals) {
                        $notes[] = 'Validation: '.implode('; ', $vals);
                    }
                }
                if (isset($arguments['summary']) && \is_string($arguments['summary']) && '' !== $arguments['summary']) {
                    $notes[] = 'Summary: '.$arguments['summary'];
                }
                if (isset($arguments['workLog']) && \is_array($arguments['workLog'])) {
                    foreach (array_values(array_filter($arguments['workLog'], is_string(...))) as $line) {
                        $notes[] = $line;
                    }
                }

                if ([] === $notes) {
                    return ToolResult::text('No updates to apply (no fields provided).', ['task' => $task]);
                }

                if (null !== ($interrupt = $control->interrupted('Cancelled before writing task metadata.'))) {
                    return $interrupt;
                }

                $text = TaskMarkdown::appendLog($text, $notes);
                if (false === file_put_contents($task->path, $text)) {
                    throw new \RuntimeException('Failed to write task file: '.$task->path);
                }

                // NOTE: No git commit to code repo. Task board is external.

                return ToolResult::text(
                    implode("\n", array_merge(['Updated '.$this->store->rel($taskRoot, $task->path).'.'], $notes)),
                    ['path' => $task->path, 'notes' => $notes]
                );
            },
            $control->cancellationToken,
            $control->deadlineNs,
            $control->timeoutSeconds,
        );

        if (InvocationControl::isInterruptMap($locked)) {
            return $locked;
        }

        return $locked;
    }
}
