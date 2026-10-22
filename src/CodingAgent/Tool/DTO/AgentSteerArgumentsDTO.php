<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\DTO;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\Expression('(this.artifact_id === null) != (this.agent_run_id === null)', message: 'Supply exactly one of artifact_id or agent_run_id.')]
final readonly class AgentSteerArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Agent-authored UTF-8 text, nonblank and at most 8192 bytes.', minLength: 1, maxLength: 8192)]
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 8192, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Charset('UTF-8')]
        public string $text,
        #[Schema(description: 'Current direct child artifact id. Supply exactly one child handle.')]
        #[Assert\NotBlank(allowNull: true)] public ?string $artifact_id = null,
        #[Schema(description: 'Current direct child run id. Supply exactly one child handle.')]
        #[Assert\NotBlank(allowNull: true)] public ?string $agent_run_id = null,
    ) {
    }
}
