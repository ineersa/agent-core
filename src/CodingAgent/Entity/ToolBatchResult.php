<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Native Messenger envelope owned by one batch/call identity. Written through DBAL. */
#[ORM\Entity]
#[ORM\Table(name: 'tool_batch_result')]
class ToolBatchResult
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

    #[ORM\Column(name: 'payload_json', type: 'text')]
    public string $payloadJson = '';
}
