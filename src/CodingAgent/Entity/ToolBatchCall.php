<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Bounded file reference owned by one batch/call identity. Written through DBAL. */
#[ORM\Entity]
#[ORM\Table(name: 'tool_batch_call')]
class ToolBatchCall
{
    #[ORM\Id]
    #[ORM\Column(name: 'run_id', type: 'string', length: 255)]
    public string $runId = '';

    #[ORM\Id]
    #[ORM\Column(name: 'turn_no', type: 'integer')]
    public int $turnNo = 0;

    #[ORM\Id]
    #[ORM\Column(name: 'step_id', type: 'string', length: 255)]
    public string $stepId = '';

    #[ORM\Id]
    #[ORM\Column(name: 'tool_call_id', type: 'string', length: 255)]
    public string $toolCallId = '';

    #[ORM\Column(name: 'order_index', type: 'integer')]
    public int $orderIndex = 0;

    #[ORM\Column(type: 'string', length: 32)]
    public string $mode = 'sequential';

    #[ORM\Column(name: 'body_reference_json', type: 'string', length: 16384)]
    public string $bodyReferenceJson = '';
}
