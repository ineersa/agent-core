<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\DTO;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AgentResumeArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Child artifact id (e.g. agent_abc123).')]
        #[Assert\When(expression: 'this.agent_run_id === null', constraints: [new Assert\NotBlank()])]
        public ?string $artifact_id = null,
        #[Schema(description: 'Continuation task.')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $task = null,
        #[Schema(description: 'Child AgentCore run id.')]
        public ?string $agent_run_id = null,
    ) {
    }
}
