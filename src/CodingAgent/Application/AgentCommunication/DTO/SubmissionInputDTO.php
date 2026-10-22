<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubmissionInputDTO
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 8192, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Regex(pattern: '//u')]
        public string $text,
        #[Assert\NotBlank(allowNull: true)] public ?string $artifact_id = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $agent_run_id = null,
    ) {
    }
}
