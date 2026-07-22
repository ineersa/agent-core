<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'deferred_tool_completion')]
#[ORM\HasLifecycleCallbacks]
class DeferredToolCompletion
{
    use TimestampableLifecycleTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;

    #[ORM\Column(name: 'deferred_id', type: 'string', length: 36, unique: true)]
    public string $deferredId = '';

    #[ORM\Column(name: 'run_id', type: 'string', length: 255)]
    public string $runId = '';

    #[ORM\Column(name: 'turn_no', type: 'integer')]
    public int $turnNo = 0;

    #[ORM\Column(name: 'step_id', type: 'string', length: 255)]
    public string $stepId = '';

    #[ORM\Column(type: 'integer')]
    public int $attempt = 0;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 255)]
    public string $idempotencyKey = '';

    #[ORM\Column(name: 'tool_call_id', type: 'string', length: 255)]
    public string $toolCallId = '';

    #[ORM\Column(name: 'tool_name', type: 'string', length: 255)]
    public string $toolName = '';

    /** @var string JSON-encoded arguments */
    #[ORM\Column(type: 'text')]
    public string $arguments = '{}';

    #[ORM\Column(name: 'order_index', type: 'integer')]
    public int $orderIndex = 0;

    #[ORM\Column(name: 'tool_idempotency_key', type: 'string', length: 255, nullable: true)]
    public ?string $toolIdempotencyKey = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    public ?string $mode = null;

    #[ORM\Column(name: 'timeout_seconds', type: 'integer', nullable: true)]
    public ?int $timeoutSeconds = null;

    #[ORM\Column(name: 'max_parallelism', type: 'integer', nullable: true)]
    public ?int $maxParallelism = null;

    /** @var string|null JSON */
    #[ORM\Column(name: 'assistant_message', type: 'text', nullable: true)]
    public ?string $assistantMessage = null;

    /** @var string|null JSON */
    #[ORM\Column(name: 'arg_schema', type: 'text', nullable: true)]
    public ?string $argSchema = null;

    #[ORM\Column(name: 'tools_ref', type: 'string', length: 255, nullable: true)]
    public ?string $toolsRef = null;

    #[ORM\Column(type: 'string', length: 32)]
    public string $status = 'pending';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }
}
