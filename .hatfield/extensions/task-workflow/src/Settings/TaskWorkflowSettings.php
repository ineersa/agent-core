<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Settings;

final readonly class TaskWorkflowSettings
{
    public function __construct(
        public ?string $taskRoot = null,
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function fromArray(array $settings): self
    {
        $taskRoot = isset($settings['task_root']) && \is_string($settings['task_root']) && '' !== $settings['task_root']
            ? $settings['task_root']
            : null;

        return new self($taskRoot);
    }
}
