<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine ORM entity for the background_process table.
 *
 * Mapped fields are public for Doctrine hydration via native lazy objects
 * (DoctrineBundle 3.x default). Property hooks are supported since
 * ORM 3.4 when enable_native_lazy_objects is true.
 *
 * Lifecycle:
 *   finish(?int $exitCode, \DateTimeImmutable $finishedAt) — normal completion
 *   markStopped(\DateTimeImmutable $finishedAt) — user-initiated stop
 *   markFinishedUnclean(\DateTimeImmutable $finishedAt) — crash/unclean exit
 *
 * created_at / updated_at are \DateTimeImmutable maintained by
 * TimestampableLifecycleTrait.
 * Semantic process times (started_at, finished_at) are set explicitly
 * by BackgroundProcessManager.
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

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'exit_code', type: 'integer', nullable: true)]
    public ?int $exitCode = null;

    #[ORM\Column(name: 'stopped_by_user', type: 'boolean')]
    public bool $stoppedByUser = false;

    #[ORM\Column(name: 'status', type: 'string', enumType: BackgroundProcessStatusEnum::class)]
    public BackgroundProcessStatusEnum $status = BackgroundProcessStatusEnum::Running;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    /** No-arg constructor for Doctrine hydration. Sets timestamp defaults. */
    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Mark this process as finished with an optional exit code.
     */
    public function finish(?int $exitCode, \DateTimeImmutable $finishedAt): void
    {
        $this->exitCode = $exitCode;
        $this->finishedAt = $finishedAt;
        $this->status = BackgroundProcessStatusEnum::Finished;
    }

    /**
     * Mark this process as stopped by the user.
     */
    public function markStopped(\DateTimeImmutable $finishedAt): void
    {
        $this->stoppedByUser = true;
        $this->finishedAt = $finishedAt;
        $this->status = BackgroundProcessStatusEnum::Stopped;
    }

    /**
     * Mark this process as finished uncleanly (crash / SIGKILL / no status file).
     */
    public function markFinishedUnclean(\DateTimeImmutable $finishedAt): void
    {
        $this->exitCode = null;
        $this->finishedAt = $finishedAt;
        $this->status = BackgroundProcessStatusEnum::FinishedUnclean;
    }
}
