<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Terminal reservation refusal retained independently of rolled-back batch rows. */
#[ORM\Entity]
#[ORM\Table(name: 'child_reservation_refusal')]
final class ChildReservationRefusal
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $identity;

    #[ORM\ManyToOne(targetEntity: HatfieldSession::class)]
    #[ORM\JoinColumn(name: 'owner_session_id', nullable: false, onDelete: 'CASCADE')]
    public HatfieldSession $owner;

    #[ORM\Column(name: 'input_hash', length: 64)]
    public string $inputHash;

    #[ORM\Column(name: 'refusal_message', type: 'text')]
    public string $refusalMessage;
}
