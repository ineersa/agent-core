<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'run_command')]
#[ORM\UniqueConstraint(name: 'uniq_run_command_identity', columns: ['run_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_run_command_pending', columns: ['run_id', 'status', 'id'])]
class CommandRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(name: 'run_id', length: 255)]
    public string $runId = '';

    #[ORM\Column(name: 'idempotency_key', length: 255)]
    public string $idempotencyKey = '';

    #[ORM\Column(type: 'text')]
    public string $status = 'pending';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $payload = null;

    #[ORM\Column(name: 'payload_hash', length: 64, nullable: true)]
    public ?string $payloadHash = null;
}
