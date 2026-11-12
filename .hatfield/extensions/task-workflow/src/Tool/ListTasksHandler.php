<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Validation\TaskInputMapper;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorkspaceStateStore;

final readonly class ListTasksHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(private GitHubTaskStore $store, private WorkspaceStateStore $states, private TaskWorkflowSettings $settings)
    {
    }

    /** @param array<string,mixed> $arguments */
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $input = TaskInputMapper::map($arguments, 'list');
        $control = InvocationControl::fromContext($context);
        $statuses = null === $input->status ? [TaskStatusEnum::TODO, TaskStatusEnum::IN_PROGRESS, TaskStatusEnum::CODE_REVIEW] : [TaskStatusEnum::fromMixed($input->status)];
        $tasks = $this->store->list($statuses, $input->page, $input->perPage, $control);
        $rows = array_map(static fn ($task): array => [
            'number' => $task->number, 'url' => $task->url, 'title' => $task->title,
            'status' => $task->status->value ?? null, 'state' => $task->state,
            'stateReason' => $task->stateReason,
        ], $tasks);
        // Local unfinished work is independent of a remotely edited terminal field.
        // Keep it separately bounded; listing never runs hooks or removes resources.
        $records = $this->states->records($this->settings->repository);
        $pending = [];
        foreach ($records as $record) {
            if (null !== ($record['pending_operation'] ?? null) || true === ($record['setup_attempted'] ?? false)) {
                $pending[] = ['issue' => $record['issue'], 'worktree' => $record['worktree'], 'pending_operation' => $record['pending_operation'], 'setup_complete' => $record['setup_complete'], 'cleanup_complete' => $record['cleanup_complete']];
            }
        }
        $offset = ($input->page - 1) * $input->perPage;

        return ToolResult::structured(['tasks' => $rows, 'page' => $input->page, 'per_status_page_size' => $input->perPage, 'local_workspaces' => \array_slice($pending, $offset, $input->perPage), 'note' => 'Closed GitHub issues with active Workflow await finalization. Local workspaces are listed even if their remote status changed. Read the selected issue for its current body.']);
    }
}
