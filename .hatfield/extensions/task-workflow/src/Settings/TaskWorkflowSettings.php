<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Settings;

final readonly class TaskWorkflowSettings
{
    public function __construct(
        public ?string $taskRoot = null,
        public int $castorCheckTimeoutSeconds = 480,
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
        $timeout = 480;
        if (isset($settings['castor_check_timeout_seconds']) && is_numeric($settings['castor_check_timeout_seconds'])) {
            $timeout = (int) $settings['castor_check_timeout_seconds'];
        }

        return new self($taskRoot, $timeout);
    }
}
