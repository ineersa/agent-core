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
        $worktrees = new WorktreeManager($git, $exec);
        $pr = new PrManager($exec);
        $store = new TaskBoardStore($codeRoot, $config);
        $taskRoot = $store->resolveTaskRoot();
        $formatter = new TaskListFormatter($store);

        // Package-local skill root: absolute directory containing SKILL.md.
        $api->registerSkill(\dirname(__DIR__).'/skills/task-workflow');

        $api->registerPromptContributor(new WorkflowPrompt($taskRoot));

        $statusEnum = ['TODO', 'IN-PROGRESS', 'CODE-REVIEW', 'DONE', 'ARCHIVE', 'CANCELLED'];

        $api->registerTool(new ToolRegistrationDTO(
            name: 'task_list',
            description: 'List workflow tasks from the external task board (TODO, IN-PROGRESS, CODE-REVIEW, DONE). CANCELLED and ARCHIVE are omitted by default; pass status=CANCELLED to list cancelled tasks, include_archive=true or status=ARCHIVE to list archived tasks.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => $statusEnum, 'description' => 'Filter by status; omit to return all statuses except CANCELLED and ARCHIVE.'],
                    'include_archive' => [
                        'type' => 'boolean',
                        'description' => 'When true, include ARCHIVE tasks in addition to the selected status; without status, include all statuses. Default false.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            handler: new ListTasksHandler($store),
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
                    'title' => ['type' => 'string', 'minLength' => 1, 'description' => 'Short task title'],
                    'body' => ['type' => 'string', 'description' => 'Free-form notes/context for the task'],
                    'acceptance' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1], 'description' => 'Acceptance criteria bullets'],
                    'id' => ['type' => 'string', 'minLength' => 1, 'description' => 'Optional filename slug/id. Defaults to date + title slug.'],
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
            description: 'Move a task between TODO, IN-PROGRESS, CODE-REVIEW, DONE, ARCHIVE, and CANCELLED on the external task board. TODO→IN-PROGRESS creates a code worktree; IN-PROGRESS→CODE-REVIEW pushes branch and creates PR; CODE-REVIEW→DONE merges the task branch; DONE→ARCHIVE is metadata-only; ANY→CANCELLED removes a clean worktree (if present) and leaves the branch.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'task' => ['type' => 'string', 'minLength' => 1, 'description' => 'Task filename, slug, or unique substring'],
                    'to' => ['type' => 'string', 'enum' => $statusEnum, 'description' => 'Destination status.'],
                    'from' => ['type' => 'string', 'enum' => $statusEnum, 'description' => 'Optional current status used to narrow task lookup.'],
                    'forkRun' => ['type' => 'string', 'minLength' => 1, 'description' => 'Fork/subagent run ID to store in task metadata.'],
                    'summary' => ['type' => 'string', 'minLength' => 1, 'description' => 'Completion or handoff summary appended to the task work log.'],
                    'validation' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1], 'description' => 'Validation commands or results appended to the task work log.'],
                    'worktreeBase' => ['type' => 'string', 'minLength' => 1, 'description' => 'Directory for task worktrees. Defaults to ../<repo>-worktrees.'],
                    'cleanupWorktree' => ['type' => 'boolean', 'description' => 'After a successful DONE merge, remove the worktree. Default true.'],
                    'deleteBranch' => ['type' => 'boolean', 'description' => 'After a successful DONE merge, delete the task branch. Default false.'],
                    'requireCleanMain' => ['type' => 'boolean', 'description' => 'Require a clean integration checkout before the DONE merge. Default true.'],
                    'cleanupStaleIndexEntries' => ['type' => 'boolean', 'description' => 'Before the DONE merge, reset stale staged-add/deleted worktree entries (AD) in the integration checkout. Default false.'],
                    'prTitle' => ['type' => 'string', 'minLength' => 1, 'description' => 'Title for the GitHub PR when moving to CODE-REVIEW. Defaults to the task title.'],
                    'prBody' => ['type' => 'string', 'minLength' => 1, 'description' => 'Body for the GitHub PR when moving to CODE-REVIEW.'],
                    'prBaseBranch' => ['type' => 'string', 'minLength' => 1, 'description' => 'Base branch for the PR. Defaults to the repository default branch.'],
                    'pushOnly' => ['type' => 'boolean', 'description' => 'Push the branch but skip PR creation. Default false.'],
                ],
                'required' => ['task', 'to'],
                'additionalProperties' => false,
            ],
            handler: new MoveTaskHandler($store, $git, $worktrees, $pr, $exec, $codeRoot),
            promptSummary: 'Move tracked project tasks between statuses; creates worktrees, opens PRs, and merges completed task branches',
            promptGuidelines: [
                'Use move_task rather than manual status-file or worktree moves. Its schema and result describe transition preconditions, side effects, and errors; load the task-workflow skill for orchestration.',
            ],
        ));

        $api->registerTool(new ToolRegistrationDTO(
            name: 'update_task',
            description: 'Update metadata or append work log entries for an existing task (external task board) without changing its status.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'task' => ['type' => 'string', 'minLength' => 1, 'description' => 'Task filename, slug, or unique substring'],
                    'from' => ['type' => 'string', 'enum' => $statusEnum, 'description' => 'Optional current status used to narrow task lookup.'],
                    'forkRun' => ['type' => 'string', 'minLength' => 1, 'description' => 'Fork/subagent run ID to store in task metadata.'],
                    'summary' => ['type' => 'string', 'minLength' => 1, 'description' => 'Summary appended to the task work log.'],
                    'validation' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1], 'description' => 'Validation commands or results appended to the task work log.'],
                    'prUrl' => ['type' => 'string', 'minLength' => 1, 'description' => 'PR URL to store in task metadata.'],
                    'prStatus' => ['type' => 'string', 'enum' => ['open', 'merged', 'closed'], 'description' => 'PR status to store in task metadata.'],
                    'workLog' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1], 'description' => 'Custom entries appended to the task work log.'],
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
