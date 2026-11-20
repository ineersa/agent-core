<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CodeModeCallRepository::class)]
#[ORM\Table(name: 'code_mode_call')]
final class CodeModeCall
{
    /** @param array<string, mixed>|null $reply */
    public function __construct(
        #[ORM\Id, ORM\Column(length: 36)] public readonly string $invocationId,
        #[ORM\Id, ORM\Column(length: 16)] public readonly string $callId,
        #[ORM\Column(length: 64)] public readonly string $fingerprint,
        #[ORM\Column(length: 128, nullable: true)] public readonly ?string $claimant,
        #[ORM\Column(length: 36, nullable: true)] public readonly ?string $childId,
        #[ORM\Column(type: 'json', nullable: true)] public readonly ?array $reply,
    ) {
    }
}
