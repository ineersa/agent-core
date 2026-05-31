<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ineersa\CodingAgent\Tool\BackgroundProcess\BackgroundProcessRecord;

#[ORM\Entity]
#[ORM\Table(name: 'background_process')]
class BackgroundProcess
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private int $id = 0;

    #[ORM\Column(type: 'integer')]
    private int $pid = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pgid = null;

    #[ORM\Column(name: 'session_id', type: 'string')]
    private string $sessionId = '';

    #[ORM\Column(type: 'string')]
    private string $command = '';

    #[ORM\Column(name: 'log_path', type: 'string')]
    private string $logPath = '';

    #[ORM\Column(name: 'status_path', type: 'string')]
    private string $statusPath = '';

    #[ORM\Column(name: 'started_at', type: 'string')]
    private string $startedAt = '';

    #[ORM\Column(name: 'finished_at', type: 'string', nullable: true)]
    private ?string $finishedAt = null;

    #[ORM\Column(name: 'exit_code', type: 'integer', nullable: true)]
    private ?int $exitCode = null;

    #[ORM\Column(name: 'stopped_by_user', type: 'boolean')]
    private bool $stoppedByUser = false;

    #[ORM\Column(name: 'updated_at', type: 'string')]
    private string $updatedAt = '';

    public function __construct(
        int $pid,
        ?int $pgid,
        string $sessionId,
        string $command,
        string $logPath,
        string $statusPath,
        string $startedAt,
        string $updatedAt,
    ) {
        $this->pid = $pid;
        $this->pgid = $pgid;
        $this->sessionId = $sessionId;
        $this->command = $command;
        $this->logPath = $logPath;
        $this->statusPath = $statusPath;
        $this->startedAt = $startedAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getPgid(): ?int
    {
        return $this->pgid;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getLogPath(): string
    {
        return $this->logPath;
    }

    public function getStatusPath(): string
    {
        return $this->statusPath;
    }

    public function getStartedAt(): string
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?string
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?string $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function setExitCode(?int $exitCode): void
    {
        $this->exitCode = $exitCode;
    }

    public function isStoppedByUser(): bool
    {
        return $this->stoppedByUser;
    }

    public function setStoppedByUser(bool $stoppedByUser): void
    {
        $this->stoppedByUser = $stoppedByUser;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(string $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Convert to the read-only DTO for external consumption.
     */
    public function toRecord(string $status): BackgroundProcessRecord
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
            status: $status,
        );
    }
}
