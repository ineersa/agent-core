<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine ORM entity for the hatfield_session table.
 *
 * Acts as the authoritative metadata store and ID registry for Hatfield
 * sessions. The auto-increment primary key is converted to a string and
 * used as both the public session_id and AgentCore runId, preserving the
 * invariant session_id === run_id.
 *
 * There is no separate public_id column — the auto-increment integer id
 * is used directly and cast to string wherever an external string
 * identifier is needed.
 *
 * Mapped fields are public for Doctrine hydration via native lazy objects
 * (DoctrineBundle 3.x default). Property hooks are supported since
 * ORM 3.4 when enablenativelazyobjects is true.
 *
 * created_at / updated_at are \DateTimeImmutable maintained by
 * TimestampableLifecycleTrait.
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

    /** Future fork tree parent ID; null for root sessions. */
    #[ORM\Column(name: 'parent_id', type: 'string', nullable: true)]
    public ?string $parentId = null;

    /** Future fork tree root ID; null if this session is the root. */
    #[ORM\Column(name: 'root_id', type: 'string', nullable: true)]
    public ?string $rootId = null;

    /** Selected model reference (e.g. "deepseek/deepseek-v4-pro"). */
    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $model = null;

    /** Provider portion of the selected model (denormalized for fast display). */
    #[ORM\Column(name: 'model_provider', type: 'string', nullable: true)]
    public ?string $modelProvider = null;

    /** Model-name portion of the selected model (denormalized for fast display). */
    #[ORM\Column(name: 'model_name', type: 'string', nullable: true)]
    public ?string $modelName = null;

    /** Reasoning level for the current session (off/minimal/low/medium/high/xhigh). */
    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $reasoning = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    /** No-arg constructor for Doctrine hydration. Sets timestamp defaults. */
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
}
