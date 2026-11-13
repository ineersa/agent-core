<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Command;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\TaskTransitionException;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\GitHubTaskStore;

final readonly class InitializeTasksCommandHandler implements ExtensionCommandHandlerInterface
{
    public function __construct(private GitHubTaskStore $store, private TaskWorkflowSettings $settings, private string $primaryRoot)
    {
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        try {
            foreach (['setup', 'validate', 'cleanup'] as $name) {
                $hook = $this->settings->hooks[$name] ?? null;
                if (null === $hook || !is_file(str_starts_with($hook['script'], '/') ? $hook['script'] : $this->primaryRoot.'/'.$hook['script'])) {
                    throw new TaskTransitionException('Configure a committed script for each project hook before initialization.');
                }
            }
            foreach (['feature', 'bug', 'experiment'] as $name) {
                if (!is_file($this->primaryRoot.'/.github/ISSUE_TEMPLATE/'.$name.'.md')) {
                    throw new TaskTransitionException('Commit the three shared .github/ISSUE_TEMPLATE Markdown templates before initialization.');
                }
            }
            $this->store->initialize();
            $context->notify('GitHub capabilities validated; Workflow definitions initialized. Only pin Workflow in the issue UI.', 'info');
        } catch (\Throwable $exception) {
            $context->notify('Task initialization incomplete: '.$exception->getMessage(), 'error');
        }
    }
}
