<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ineersa\CodingAgent\Tool\BackgroundProcess\BackgroundProcessRecord;

/**
 * Doctrine ORM entity for the background_process table.
 *
 * Mapped fields are public — Doctrine ORM 3.6.7 hydrates via native
 * lazy objects (enabled by default in DoctrineBundle 3.x). Property
 * hooks ({ set => ... }) are not yet supported by ORM 3.6 for mapped
 * fields — the UnitOfWork attempts to unset hooked properties during
 * entity removal, which raises an Error. See:
 * https://github.com/doctrine/orm/issues/11624
 *
 * Lifecycle mutations use domain methods:
 *   finish(?int $exitCode, string $finishedAt) — normal completion
 *   markStoppedByUser(string $finishedAt) — user-initiated stop
 *   touch(string $updatedAt) — bump timestamp
 */
#[ORM\Entity]
#[ORM\Table(name: 'background_process')]
class BackgroundProcess
{
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

    #[ORM\Column(name: 'updated_at', type: 'string')]
    public string $updatedAt = '';

    /** No-arg constructor for Doctrine hydration. */
    public function __construct()
    {
    }

    /**
     * Mark this process as finished with an optional exit code.
     */
    public function finish(?int $exitCode, string $finishedAt): void
    {
        $this->exitCode = $exitCode;
        $this->finishedAt = $finishedAt;
        $this->updatedAt = $finishedAt;
    }

    /**
     * Mark this process as stopped by the user.
     */
    public function markStoppedByUser(string $finishedAt): void
    {
        $this->stoppedByUser = true;
        $this->finishedAt = $finishedAt;
        $this->updatedAt = $finishedAt;
    }

    /**
     * Update the timestamp.
     */
    public function touch(string $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Convert to the read-only DTO for external consumption.
     */
    public function toRecord(BackgroundProcessStatusEnum $status): BackgroundProcessRecord
    {
        return new BackgroundProcessRecord(
            id: $this->id,
            pid: $this->pid,
            pgid: $this->pgid,
            command: $this->command,
            logPath: $this->logPath,
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            exitCode: $this->exitCode,
            stoppedByUser: $this->stoppedByUser,
            sessionId: $this->sessionId,
            status: $status->value,
        );
    }
}
