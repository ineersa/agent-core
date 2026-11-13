<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;
use Ineersa\HatfieldExt\TaskWorkflow\Validation\TaskInputMapper;

final readonly class CreateTaskHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(private GitHubTaskStore $store)
    {
    }

    /** @param array<string,mixed> $arguments */
    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $input = TaskInputMapper::map($arguments, 'create');
        $task = $this->store->create($input->title, $input->body, InvocationControl::fromContext($context));

        return ToolResult::text('Created GitHub task with Workflow TODO.', ['task' => $task]);
    }
}
