<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\DTO;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\Expression('(this.artifact_id === null) != (this.agent_run_id === null)', message: 'Supply exactly one of artifact_id or agent_run_id.')]
final readonly class AgentCancelArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Artifact ID of the subagent or fork to stop.')]
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)] public ?string $artifact_id = null,
        #[Schema(description: 'Run ID of the subagent or fork to stop.')]
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)] public ?string $agent_run_id = null,
    ) {
    }
}
