<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Command;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\TaskListFormatter;

final readonly class TasksCommandHandler implements ExtensionCommandHandlerInterface
{
    public function __construct(
        private ?TaskStatusEnum $status,
        private string $label,
        private TaskBoardStore $store,
        private TaskListFormatter $formatter,
    ) {
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        try {
            $taskRoot = $this->store->resolveTaskRoot();
            $tasks = $this->store->listTasks($taskRoot, $this->status);
            if ([] === $tasks) {
                $context->notify('No '.$this->label.' tasks.', 'info');

                return;
            }
            $context->notify($this->formatter->format($taskRoot, $tasks), 'info');
        } catch (\Throwable $e) {
            $context->notify('Error: '.$e->getMessage(), 'error');
        }
    }
}
