<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CodeModeExecutionRepository::class)]
#[ORM\Table(name: 'code_mode_execution')]
#[ORM\UniqueConstraint(name: 'code_mode_invocation', columns: ['run_id', 'tool_call_id'])]
final class CodeModeExecution
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    public string $identity;

    #[ORM\Column(name: 'run_id', length: 255)]
    public string $runId;

    #[ORM\Column(name: 'tool_call_id', length: 255)]
    public string $toolCallId;

    #[ORM\Column(name: 'call_reference', type: Types::TEXT)]
    public string $callReference;

    #[ORM\Column]
    public bool $ready = false;

    #[ORM\Column]
    public bool $detached = false;

    #[ORM\Column]
    public bool $settled = false;

    #[ORM\Column(name: 'raw_reference', type: Types::TEXT, nullable: true)]
    public ?string $rawReference = null;

    #[ORM\Column(name: 'result_reference', type: Types::TEXT, nullable: true)]
    public ?string $resultReference = null;
}
