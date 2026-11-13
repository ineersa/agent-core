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

final readonly class ReadTaskHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(private GitHubTaskStore $store, private WorkspaceStateStore $states, private TaskWorkflowSettings $settings)
    {
    }

    /** @param array<string,mixed> $arguments */
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $input = TaskInputMapper::map($arguments, 'read');
        $control = InvocationControl::fromContext($context);
        $task = $this->store->read($input->task, $control);
        $result = ['task' => $task, 'relationships' => array_map(get_object_vars(...), $this->store->relationships($task->number, $control)), 'local_workspace' => $this->states->load($this->settings->repository, $task->number)];
        if (null !== $input->artifact) {
            $result['artifact'] = $this->store->artifact($task->number, self::artifactNumber($input->artifact, $task->url), $control);
        }

        return ToolResult::structured($result);
    }

    public static function artifactNumber(string $reference, string $issueUrl): int
    {
        // Canonical document URLs are identity, not display names. Do not accept
        // a similarly numbered artifact belonging to a different task/host.
        if (str_starts_with($reference, $issueUrl.'/artifacts/')) {
            $reference = substr($reference, \strlen($issueUrl.'/artifacts/'));
        }
        if (1 !== preg_match('/^[1-9][0-9]*$/D', $reference)) {
            throw new TaskTransitionException('Use the artifact per-issue number or its canonical issue artifact URL.');
        }

        return (int) $reference;
    }
}
