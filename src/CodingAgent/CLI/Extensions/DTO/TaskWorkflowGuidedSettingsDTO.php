<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Guided Task Workflow settings accepted by extensions:setup.
 */
final readonly class TaskWorkflowGuidedSettingsDTO
{
    public function __construct(
        #[SerializedName('task_root')]
        #[Assert\Type('string')]
        public string $taskRoot = '',
    ) {
    }
}
