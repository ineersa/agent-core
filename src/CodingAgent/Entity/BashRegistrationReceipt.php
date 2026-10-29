<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Immutable registration evidence retained independently of provisional process rows. */
#[ORM\Entity]
#[ORM\Table(name: 'bash_registration_receipt')]
final class BashRegistrationReceipt
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $identity;

    #[ORM\ManyToOne(targetEntity: HatfieldSession::class)]
    #[ORM\JoinColumn(name: 'owner_session_id', nullable: false, onDelete: 'CASCADE')]
    public HatfieldSession $owner;

    #[ORM\Column(name: 'input_hash', length: 64)]
    public string $inputHash;

    #[ORM\Column(name: 'record_id')]
    public int $recordId;

    #[ORM\Column]
    public int $pid;

    #[ORM\Column(name: 'log_path', type: 'text')]
    public string $logPath;
}
