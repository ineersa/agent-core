<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow;

use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowSkillInstaller;
use Ineersa\HatfieldExt\TaskWorkflow\Command\InitializeTasksCommandHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Command\TasksCommandHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Prompt\WorkflowPrompt;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowConfiguration;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\CreateTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ListTasksHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\MoveTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\ReadTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\TaskListFormatter;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\UpdateTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\HookRunner;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorkspaceStateStore;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class TaskWorkflowExtension implements ConfigurableExtensionInterface, LoggerAwareInterface
{
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getConfiguration(): ConfigurationInterface
    {
        return new TaskWorkflowConfiguration();
    }

    public function register(ExtensionApiInterface $api): void
    {
        $settings = TaskWorkflowSettings::fromArray($api->getSettings('task_workflow'));
        $exec = $api->exec();
        $git = new GitExecutor($exec);
        $worktrees = new WorktreeManager($git);
        $primary = $worktrees->primaryRoot($api->getCwd());
        // The stable checkout owns installed guidance, including before configuration.
        (new TaskWorkflowSkillInstaller($primary, \dirname(__DIR__), $this->logger))->install();
        $current = trim($git->gitOk(['rev-parse', '--show-toplevel'], $api->getCwd())->stdout);
        if ($current !== $primary) {
            (new TaskWorkflowSkillInstaller($current, \dirname(__DIR__), $this->logger))->install(existingManagedOnly: true);
        }
        $api->registerSkill($primary.'/.hatfield/skills/'.TaskWorkflowSkillInstaller::SKILL_NAME);
        if ('' === $settings->repository) {
            $this->logger->warning('task_workflow.unconfigured', ['component' => 'task_workflow', 'event_type' => 'configuration_required']);

            return;
        }
        $states = new WorkspaceStateStore($git, $primary);
        $store = new GitHubTaskStore($exec, $primary, $settings);
        $pr = new PrManager($exec, $primary, $settings);
        $api->registerPromptContributor(new WorkflowPrompt($primary, $settings->repository));
        $statuses = array_map(static fn (TaskStatusEnum $status): string => $status->value, TaskStatusEnum::cases());
        $text = ['type' => 'string'];
        $reference = ['type' => 'string', 'minLength' => 1, 'description' => 'Issue number or canonical GitHub issue URL.'];
        $schema = static fn (array $properties, array $required = []): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        $api->registerTool(new ToolRegistrationDTO(
            name: 'task_list',
            description: 'List bounded GitHub Workflow pages including closed active issues and unfinished local workspaces. Listing never runs hooks.',
            parametersJsonSchema: $schema(['status' => ['type' => 'string', 'enum' => $statuses], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000], 'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]),
            handler: new ListTasksHandler($store, $states, $settings),
            promptSummary: 'task_list [status] [page] [perPage] — list GitHub tasks and local pending work',
            promptGuidelines: ['List tasks only when choosing work or resolving its identity. Read a selected issue directly.'],
        ));
        $api->registerTool(new ToolRegistrationDTO(
            name: 'create_task',
            description: 'Create a GitHub issue with Workflow TODO. Submit prepared Markdown from a project feature, bug or experiment template; no frontmatter.',
            parametersJsonSchema: $schema(['title' => ['type' => 'string', 'minLength' => 1], 'body' => ['type' => 'string', 'minLength' => 1]], ['title', 'body']),
            handler: new CreateTaskHandler($store),
            promptSummary: 'create_task title body — create a GitHub TODO issue',
        ));
        $api->registerTool(new ToolRegistrationDTO(
            name: 'read_task',
            description: 'Read current issue specification, Workflow, native development links and local recovery. Optionally read an explicitly numbered artifact.',
            parametersJsonSchema: $schema(['task' => $reference, 'artifact' => $text], ['task']),
            handler: new ReadTaskHandler($store, $states, $settings),
            promptSummary: 'read_task task [artifact] — read the selected GitHub task',
        ));
        $api->registerTool(new ToolRegistrationDTO(
            name: 'update_task',
            description: 'Edit the current issue title/body, or set artifact to target those edits at that document instead. At least one edit or intentional discussion comment is required. Does not change Workflow.',
            parametersJsonSchema: $schema([
                'task' => $reference,
                'title' => ['type' => 'string', 'description' => 'New issue title, or artifact name when artifact is selected.'],
                'body' => ['type' => 'string', 'description' => 'Replacement issue body, or artifact body when artifact is selected.'],
                'artifact' => ['type' => 'string', 'description' => 'Artifact number or URL selecting the document to edit with title/body. Selection alone is not an edit.'],
                'comment' => $text,
            ], ['task']),
            handler: new UpdateTaskHandler($store, $states, $settings),
            promptSummary: 'update_task task [title] [body] [artifact] [comment] — edit current task content',
            promptGuidelines: ['Keep the specification, decisions, test plan and actual QA current in the issue body. Do not append work logs or agent metadata.'],
        ));
        $api->registerTool(new ToolRegistrationDTO(
            name: 'move_task',
            description: 'Run an ordered task transition, including configured hooks, native branches/PRs and recoverable cleanup. Terminal success requires cleanup and both Git registry and directory removal. Merge only when authorized and project review is satisfied.',
            parametersJsonSchema: $schema([
                'task' => $reference, 'to' => ['type' => 'string', 'enum' => $statuses], 'from' => ['type' => 'string', 'enum' => $statuses],
                'adopt' => ['type' => 'boolean', 'description' => 'Explicitly adopt an unmanaged issue as TODO, including historical closed issues.'],
                'pr' => ['type' => 'string', 'description' => 'Select an ambiguous native task PR by number or URL.'],
                'branch' => ['type' => 'string', 'description' => 'Select an ambiguous native linked branch.'],
                'acceptedHead' => ['type' => 'string', 'description' => 'Explicit inspected/reviewed PR SHA for DONE, required without a local submitted SHA.'],
                'prTitle' => $text, 'prBody' => $text,
            ], ['task', 'to']),
            handler: new MoveTaskHandler($store, $git, $worktrees, $pr, new HookRunner($exec), $states, $primary, $settings),
            promptSummary: 'move_task task to [from] — run a recoverable GitHub task transition',
            promptGuidelines: ['Main alone calls transitions. Honor the requested delivery endpoint. Never infer merge authorization.', 'After a hook attempt, put its actual outcome into the current issue QA summary; unrun checks stay unrun.', 'If finalization fails, retain resources and use the reported state to retry the same operation.'],
        ));
        foreach ([['tasks', null], ['tasks-todo', TaskStatusEnum::TODO], ['tasks-in-progress', TaskStatusEnum::IN_PROGRESS], ['tasks-code-review', TaskStatusEnum::CODE_REVIEW], ['tasks-done', TaskStatusEnum::DONE]] as [$name, $status]) {
            $api->registerCommand(new CommandDefinitionDTO(name: $name, description: 'List GitHub tasks'.(null === $status ? '' : ' in '.$status->value), usage: '/'.$name.' [page]'), new TasksCommandHandler($status, $store, $states, $settings, new TaskListFormatter()));
        }
        $api->registerCommand(new CommandDefinitionDTO(name: 'tasks-init', description: 'Deliberately validate GitHub capabilities and initialize Workflow definitions', usage: '/tasks-init'), new InitializeTasksCommandHandler($store, $settings, $primary));
    }
}
