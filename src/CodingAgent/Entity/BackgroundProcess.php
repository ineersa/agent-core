<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine ORM entity for the background_process table.
 *
 * Mapped fields are public — ORM hydrates via native lazy objects
 * (DoctrineBundle 3.x default). Property hooks are not yet supported
 * for mapped fields (UnitOfWork unset during removal raises Error):
 * https://github.com/doctrine/orm/issues/11624
 *
 * Lifecycle:
 *   finish(?int $exitCode, ?string $finishedAt) — normal completion
 *   markStopped(string $finishedAt) — user-initiated stop
 *   markFinishedUnclean(string $finishedAt) — crash/unclean exit
 *
 * created_at / updated_at are maintained by TimestampableLifecycleTrait.
 * Semantic process times (started_at, finished_at) are set explicitly.
 */
#[ORM\Entity(repositoryClass: BackgroundProcessRepository::class)]
#[ORM\Table(name: 'background_process')]
#[ORM\HasLifecycleCallbacks]
class BackgroundProcess
{
    use TimestampableLifecycleTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;

    #[ORM\Column(type: 'integer')]
    public int $pid = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $pgid = null;

    #[ORM\Column(name: 'session_id', type: 'string')]
    public string $sessionId = '';

    #[ORM\Column(type: 'string')]
    public string $command = '';

    #[ORM\Column(name: 'log_path', type: 'string')]
    public string $logPath = '';

    #[ORM\Column(name: 'status_path', type: 'string')]
    public string $statusPath = '';

    #[ORM\Column(name: 'started_at', type: 'string')]
    public string $startedAt = '';

    #[ORM\Column(name: 'finished_at', type: 'string', nullable: true)]
    public ?string $finishedAt = null;

    #[ORM\Column(name: 'exit_code', type: 'integer', nullable: true)]
    public ?int $exitCode = null;

    #[ORM\Column(name: 'stopped_by_user', type: 'boolean')]
    public bool $stoppedByUser = false;

    #[ORM\Column(name: 'status', type: 'string', enumType: BackgroundProcessStatusEnum::class)]
    public BackgroundProcessStatusEnum $status = BackgroundProcessStatusEnum::Running;

    #[ORM\Column(name: 'created_at', type: 'string')]
    public string $createdAt = '';

    #[ORM\Column(name: 'updated_at', type: 'string')]
    public string $updatedAt = '';

    /** No-arg constructor for Doctrine hydration. */
    public function __construct()
    {
    }

    /**
     * Mark this process as finished with an optional exit code.
     */
    public function finish(?int $exitCode, ?string $finishedAt): void
    {
        $this->exitCode = $exitCode;
        $this->finishedAt = $finishedAt;
        $this->status = BackgroundProcessStatusEnum::Finished;
    }

    /**
     * Mark this process as stopped by the user.
     */
    public function markStopped(string $finishedAt): void
    {
        $this->stoppedByUser = true;
        $this->finishedAt = $finishedAt;
        $this->status = BackgroundProcessStatusEnum::Stopped;
    }

    /**
     * Mark this process as finished uncleanly (crash / SIGKILL / no status file).
     */
    public function markFinishedUnclean(string $finishedAt): void
    {
        $this->exitCode = null;
        $this->finishedAt = $finishedAt;
        $this->status = BackgroundProcessStatusEnum::FinishedUnclean;
    }
}
