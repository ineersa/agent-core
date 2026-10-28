<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable acceptance/retirement fence, independent of provisional process cleanup. */
#[ORM\Entity]
#[ORM\Table(name: 'bash_launch_ownership')]
final class BashLaunchOwnership
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $identity;

    #[ORM\ManyToOne(targetEntity: HatfieldSession::class)]
    #[ORM\JoinColumn(name: 'owner_session_id', nullable: false, onDelete: 'CASCADE')]
    public HatfieldSession $owner;

    #[ORM\Column]
    public bool $accepted;
}
