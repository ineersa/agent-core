<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskTransitionException;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;
use Ineersa\HatfieldExt\TaskWorkflow\Validation\TaskInputMapper;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorkspaceStateStore;

final readonly class UpdateTaskHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(private GitHubTaskStore $store, private WorkspaceStateStore $states, private TaskWorkflowSettings $settings)
    {
    }

    /** @param array<string,mixed> $arguments */
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $input = TaskInputMapper::map($arguments, 'update');
        if (!isset($arguments['title'], $arguments['body']) && null === $input->body && '' === $input->title && null === $input->comment) {
            throw new TaskTransitionException('Provide an intentional title/body edit or discussion comment.');
        }
        $control = InvocationControl::fromContext($context);
        $task = $this->store->read($input->task, $control);
        $result = $this->states->lock($this->settings->repository, $task->number)->withLock(function () use ($task, $input, $arguments, $control): string {
            $task = $this->store->read($task->number, $control);
            $result = [];
            if (null !== $input->artifact) {
                $number = ReadTaskHandler::artifactNumber($input->artifact, $task->url);
                $artifact = $this->store->artifact($task->number, $number, $control);
                $result['artifact'] = $this->store->editArtifact($task->number, $number, $artifact, isset($arguments['title']) ? $input->title : $artifact['name'], $input->body ?? $artifact['body'], $control);
            } elseif (isset($arguments['title']) || null !== $input->body) {
                $task = $this->store->edit($task, isset($arguments['title']) ? $input->title : null, $input->body, $control);
            }
            if (null !== $input->comment) {
                $result['comment'] = $this->store->comment($task->number, $input->comment, $control);
            }

            return ToolResult::text('Updated current task content; Workflow unchanged.', ['task' => $task, ...$result]);
        }, $control->cancellationToken, $control->deadlineNs, $control->timeoutSeconds);

        return \is_array($result) ? ToolResult::structured($result) : $result;
    }
}
