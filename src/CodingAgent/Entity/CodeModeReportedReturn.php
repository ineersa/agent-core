<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CodeModeReportedReturnRepository::class)]
#[ORM\Table(name: 'code_mode_reported_return')]
final class CodeModeReportedReturn
{
    /** @param array<string, mixed> $reply */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        public readonly string $invocationId,
        #[ORM\Column(type: 'json')]
        public readonly array $reply,
    ) {
    }
}
