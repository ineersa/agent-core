<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine ORM entity for the hatfield_session table.
 *
 * Acts as the authoritative ID registry for Hatfield sessions.
 * Auto-increment primary key is converted to a string and used as
 * both the public session_id and AgentCore runId, preserving the
 * invariant session_id === run_id.
 *
 * Mapped fields are public for Doctrine hydration (native lazy objects).
 * Property hooks are not yet supported for mapped fields by ORM 3.6.
 *
 * created_at / updated_at are maintained by TimestampableLifecycleTrait.
 *
 * @see HatfieldSessionStore
 * @see HatfieldSessionRepository
 */
#[ORM\Entity(repositoryClass: HatfieldSessionRepository::class)]
#[ORM\Table(name: 'hatfield_session')]
#[ORM\HasLifecycleCallbacks]
class HatfieldSession
{
    use TimestampableLifecycleTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;

    #[ORM\Column(type: 'string')]
    public string $cwd = '';

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $prompt = null;

    #[ORM\Column(name: 'created_at', type: 'string')]
    public string $createdAt = '';

    #[ORM\Column(name: 'updated_at', type: 'string')]
    public string $updatedAt = '';

    /** No-arg constructor for Doctrine hydration. */
    public function __construct()
    {
    }
}
