<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Command\TasksCommandHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Prompt\WorkflowPrompt;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\CreateTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ListTasksHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\MoveTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\TaskListFormatter;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\UpdateTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;

final readonly class TaskWorkflowExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $settings = $api->getSettings('task_workflow');
        $config = TaskWorkflowSettings::fromArray($settings);
        $codeRoot = $api->getCwd();

        $exec = $api->exec();
        $git = new GitExecutor($exec);
        $worktrees = new WorktreeManager($git);
        $pr = new PrManager($exec);
        $store = new TaskBoardStore($codeRoot, $config);
        $taskRoot = $store->resolveTaskRoot();
        $formatter = new TaskListFormatter($store);

        $api->registerPromptContributor(new WorkflowPrompt($taskRoot));

        $statusEnum = ['TODO', 'IN-PROGRESS', 'CODE-REVIEW', 'DONE'];

        $api->registerTool(new ToolRegistrationDTO(
            name: 'task_list',
            description: 'List workflow tasks from the external task board (TODO, IN-PROGRESS, CODE-REVIEW, DONE).',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => $statusEnum, 'description' => 'Filter by status'],
                ],
                'additionalProperties' => false,
            ],
            handler: new ListTasksHandler($store, $formatter),
            promptSummary: 'List project workflow tasks from the external task board',
            promptGuidelines: [
                'Use task_list before starting tracked project work to understand TODO and IN-PROGRESS tasks.',
            ],
        ));

        $api->registerTool(new ToolRegistrationDTO(
            name: 'create_task',
            description: 'Create a Markdown task file in the external task board TODO directory.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Short task title'],
                    'body' => ['type' => 'string', 'description' => 'Free-form notes/context for the task'],
                    'acceptance' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Acceptance criteria bullets'],
                    'id' => ['type' => 'string', 'description' => 'Optional filename slug/id. Defaults to date + title slug.'],
                ],
                'required' => ['title'],
                'additionalProperties' => false,
            ],
            handler: new CreateTaskHandler($store),
            promptSummary: 'Create a tracked project task (external task board)',
            promptGuidelines: [
                'Use create_task for user-approved follow-up work that should be tracked on the task board.',
            ],
        ));

        $api->registerTool(new ToolRegistrationDTO(
            name: 'move_task',
            description: 'Move a task between TODO, IN-PROGRESS, CODE-REVIEW, and DONE on the external task board. TODO→IN-PROGRESS creates a code worktree; IN-PROGRESS→CODE-REVIEW pushes branch and creates PR; CODE-REVIEW→DONE merges the task branch.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'task' => ['type' => 'string', 'description' => 'Task filename, slug, or unique substring'],
                    'to' => ['type' => 'string', 'enum' => $statusEnum],
                    'from' => ['type' => 'string', 'enum' => $statusEnum],
                    'forkRun' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                    'validation' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'worktreeBase' => ['type' => 'string'],
                    'cleanupWorktree' => ['type' => 'boolean'],
                    'deleteBranch' => ['type' => 'boolean'],
                    'requireCleanMain' => ['type' => 'boolean'],
                    'cleanupStaleIndexEntries' => ['type' => 'boolean'],
                    'prTitle' => ['type' => 'string'],
                    'prBody' => ['type' => 'string'],
                    'prBaseBranch' => ['type' => 'string'],
                    'pushOnly' => ['type' => 'boolean'],
                    'castorCheckTimeoutSeconds' => ['type' => 'number', 'minimum' => 60, 'maximum' => 1200],
                ],
                'required' => ['task', 'to'],
                'additionalProperties' => false,
            ],
            handler: new MoveTaskHandler($store, $git, $worktrees, $pr, $exec, $config, $codeRoot),
            promptSummary: 'Move tracked project tasks between statuses; creates worktrees, opens PRs, and merges completed task branches',
            promptGuidelines: [
                'Use move_task instead of manual mv/git worktree commands for tracked task workflow transitions.',
                'Use move_task with to="IN-PROGRESS" before launching a worker/fork for a tracked task.',
                'Use move_task with to="CODE-REVIEW" after the worktree branch is committed and ready for review; this automatically runs deterministic castor check in the worktree, then pushes the branch and creates a PR. Run focused Castor validation (castor test, castor deptrac, castor phpstan, castor cs-check) yourself before moving to catch issues early.',
                'Use move_task with to="DONE" only after PR review is approved and the user/parent decides to merge; move_task reports merge conflicts and leaves the task in CODE-REVIEW on failure.',
            ],
        ));

        $api->registerTool(new ToolRegistrationDTO(
            name: 'update_task',
            description: 'Update metadata or append work log entries for an existing task (external task board) without changing its status.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'task' => ['type' => 'string', 'description' => 'Task filename, slug, or unique substring'],
                    'from' => ['type' => 'string', 'enum' => $statusEnum],
                    'forkRun' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                    'validation' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'prUrl' => ['type' => 'string'],
                    'prStatus' => ['type' => 'string'],
                    'workLog' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['task'],
                'additionalProperties' => false,
            ],
            handler: new UpdateTaskHandler($store),
            promptSummary: 'Update task metadata fields or append work log entries without moving the task between statuses',
            promptGuidelines: [
                'Use update_task instead of editing task files directly when recording fork run IDs, summaries, validation results, PR information, or work log entries.',
                'update_task does not change the task status or move the file. Use move_task for status changes.',
            ],
        ));

        $this->registerTaskCommands($api, $store, $formatter);
    }

    private function registerTaskCommands(ExtensionApiInterface $api, TaskBoardStore $store, TaskListFormatter $formatter): void
    {
        $commands = [
            ['tasks', null, 'all', 'List all tasks', '/tasks'],
            ['tasks-todo', TaskStatusEnum::TODO, 'TODO', 'List TODO tasks', '/tasks-todo'],
            ['tasks-in-progress', TaskStatusEnum::IN_PROGRESS, 'IN-PROGRESS', 'List IN-PROGRESS tasks', '/tasks-in-progress'],
            ['tasks-code-review', TaskStatusEnum::CODE_REVIEW, 'CODE-REVIEW', 'List CODE-REVIEW tasks', '/tasks-code-review'],
            ['tasks-done', TaskStatusEnum::DONE, 'DONE', 'List DONE tasks', '/tasks-done'],
        ];

        foreach ($commands as [$name, $status, $label, $description, $usage]) {
            $api->registerCommand(
                new CommandDefinitionDTO(
                    name: $name,
                    description: $description,
                    usage: $usage,
                ),
                new TasksCommandHandler($status, $label, $store, $formatter),
            );
        }
    }
}
