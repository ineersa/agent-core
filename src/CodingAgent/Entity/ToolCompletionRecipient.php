<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Canonical-derived recipient projection, separate from pending mailbox rows. */
#[ORM\Entity]
#[ORM\Table(name: 'tool_completion_recipient')]
final class ToolCompletionRecipient
{
    #[ORM\Id]
    #[ORM\Column(name: 'destination_run_id', length: 255)]
    public string $destinationRunId;

    #[ORM\Id]
    #[ORM\Column(name: 'message_id', length: 255)]
    public string $messageId;

    #[ORM\Column(name: 'reference_json', type: Types::TEXT)]
    public string $referenceJson;

    #[ORM\Column(name: 'received_turn_no')]
    public int $receivedTurnNo;

    #[ORM\Column(name: 'received_sequence')]
    public int $receivedSequence;

    #[ORM\Column(length: 16)]
    public string $disposition;

    #[ORM\Column(name: 'state_reason', length: 255)]
    public string $stateReason;

    #[ORM\Column(name: 'applied_sequence', nullable: true)]
    public ?int $appliedSequence = null;
}
