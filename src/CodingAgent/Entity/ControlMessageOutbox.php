<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ORM metadata for control_message_outbox.
 *
 * DoctrineControlMessageOutbox reads and writes rows through DBAL only.
 * Payloads are small control messages, never invocation request/result bodies.
 */
#[ORM\Entity]
#[ORM\Table(name: 'control_message_outbox')]
#[ORM\Index(name: 'idx_control_message_outbox_run', columns: ['run_id'])]
class ControlMessageOutbox
{
    #[ORM\Id]
    #[ORM\Column(name: 'run_id', type: 'string', length: 255)]
    public string $runId = '';

    #[ORM\Id]
    #[ORM\Column(name: 'identity', type: 'string', length: 128)]
    public string $identity = '';

    #[ORM\Column(name: 'destination', type: 'string', length: 32)]
    public string $destination = '';

    #[ORM\Column(name: 'payload_json', type: 'text')]
    public string $payloadJson = '';

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    public ?\DateTimeInterface $createdAt = null;
}
