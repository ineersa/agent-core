<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;

final readonly class CreateTaskHandler implements ExtensionToolHandlerInterface
{
    public function __construct(
        private TaskBoardStore $store,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: list<array{type: string, text: string}>, details: string}
     */
    public function __invoke(array $arguments): array
    {
        $title = $arguments['title'] ?? null;
        if (!\is_string($title) || '' === trim($title)) {
            throw new \InvalidArgumentException('title is required');
        }

        $body = isset($arguments['body']) && \is_string($arguments['body']) ? $arguments['body'] : null;
        $acceptance = null;
        if (isset($arguments['acceptance']) && \is_array($arguments['acceptance'])) {
            $acceptance = array_values(array_filter($arguments['acceptance'], is_string(...)));
        }
        $id = isset($arguments['id']) && \is_string($arguments['id']) ? $arguments['id'] : null;

        $taskRoot = $this->store->resolveTaskRoot();
        $this->store->ensureTaskDirs($taskRoot);
        $lock = new TaskBoardLock(TaskBoardLock::lockPathForRoot($taskRoot));

        return $lock->withLock(function () use ($taskRoot, $title, $body, $acceptance, $id): array {
            $slug = TaskMarkdown::slugify($id ?? (TaskMarkdown::today().'-'.$title));
            $path = $taskRoot.'/TODO/'.$slug.'.md';
            if (is_file($path)) {
                throw new \RuntimeException('Task already exists: '.$this->store->rel($taskRoot, $path));
            }

            $content = TaskMarkdown::renderTask($title, $body, $acceptance);
            if (false === file_put_contents($path, $content)) {
                throw new \RuntimeException('Failed to write task file: '.$path);
            }

            // NOTE: No git commit to code repo. Task board is external.

            return ToolResult::text('Created '.$this->store->rel($taskRoot, $path), ['path' => $path]);
        });
    }
}
