<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ineersa\CodingAgent\Tool\BackgroundProcess\BackgroundProcessRecord;

/**
 * Doctrine ORM entity for the background_process table.
 *
 * Uses PHP 8.5 asymmetric visibility for read-only public access to mapped fields.
 * Mutable lifecycle fields (finishedAt, exitCode, stoppedByUser, updatedAt) are
 * modified through explicit domain methods, not trivial setters.
 *
 * Schema is managed by Doctrine migrations — no runtime CREATE TABLE/ALTER TABLE.
 */
#[ORM\Entity]
#[ORM\Table(name: 'background_process')]
class BackgroundProcess
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public private(set) int $id = 0;

    #[ORM\Column(type: 'integer')]
    public private(set) int $pid = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    public private(set) ?int $pgid = null;

    #[ORM\Column(name: 'session_id', type: 'string')]
    public private(set) string $sessionId = '';

    #[ORM\Column(type: 'string')]
    public private(set) string $command = '';

    #[ORM\Column(name: 'log_path', type: 'string')]
    public private(set) string $logPath = '';

    #[ORM\Column(name: 'status_path', type: 'string')]
    public private(set) string $statusPath = '';

    #[ORM\Column(name: 'started_at', type: 'string')]
    public private(set) string $startedAt = '';

    #[ORM\Column(name: 'finished_at', type: 'string', nullable: true)]
    public private(set) ?string $finishedAt = null;

    #[ORM\Column(name: 'exit_code', type: 'integer', nullable: true)]
    public private(set) ?int $exitCode = null;

    #[ORM\Column(name: 'stopped_by_user', type: 'boolean')]
    public private(set) bool $stoppedByUser = false;

    #[ORM\Column(name: 'updated_at', type: 'string')]
    public private(set) string $updatedAt = '';

    /** No-arg constructor for Doctrine hydration. Properties set via reflection/static factory. */
    public function __construct()
    {
    }

    /**
     * Create a new background process entity.
     *
     * Static factory is the standard Doctrine pattern for entity creation.
     * Doctrine hydrates via reflection; user code creates via this factory.
     *
     * @param int      $pid        Process ID
     * @param int|null $pgid       Process group ID (null if not tracked)
     * @param string   $sessionId  Hatfield session/run ID
     * @param string   $command    Shell command executed
     * @param string   $logPath    Path to process output log
     * @param string   $statusPath Path to status file
     * @param string   $startedAt  ISO-8601 start timestamp
     * @param string   $updatedAt  ISO-8601 update timestamp
     */
    public static function create(
        int $pid,
        ?int $pgid,
        string $sessionId,
        string $command,
        string $logPath,
        string $statusPath,
        string $startedAt,
        string $updatedAt,
    ): self {
        $entity = new self();
        $entity->pid = $pid;
        $entity->pgid = $pgid;
        $entity->sessionId = $sessionId;
        $entity->command = $command;
        $entity->logPath = $logPath;
        $entity->statusPath = $statusPath;
        $entity->startedAt = $startedAt;
        $entity->updatedAt = $updatedAt;

        return $entity;
    }

    /**
     * Mark this process as finished.
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
     * Update the timestamp for the entity.
     */
    public function touch(string $updatedAt): void
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
