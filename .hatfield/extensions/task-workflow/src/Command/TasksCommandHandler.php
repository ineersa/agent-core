<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Command;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\TaskListFormatter;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorkspaceStateStore;

final readonly class TasksCommandHandler implements ExtensionCommandHandlerInterface
{
    public function __construct(private ?TaskStatusEnum $status, private GitHubTaskStore $store, private WorkspaceStateStore $states, private TaskWorkflowSettings $settings, private TaskListFormatter $formatter)
    {
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        try {
            $page = '' === trim($args) ? 1 : (int) trim($args);
            $tasks = $this->store->list(null === $this->status ? [TaskStatusEnum::TODO, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::CODE_REVIEW] : [$this->status], $page);
            $records = $this->states->records($this->settings->repository);
            $local = [];
            foreach ($records as $record) {
                $local[$record['issue']] = $record;
            }
            $text = $this->formatter->format($tasks, $local);
            foreach (\array_slice($records, ($page - 1) * 30, 30) as $record) {
                $text .= "\nLocal #".$record['issue'].': '.$record['worktree'].' ('.($record['pending_operation'] ?? 'workspace owned').')';
            }
            $context->notify($text, 'info');
        } catch (\Throwable $exception) {
            // Surface failed remote reads, never turn them into an empty task list.
            $context->notify('Task listing failed: '.$exception->getMessage(), 'error');
        }
    }
}
