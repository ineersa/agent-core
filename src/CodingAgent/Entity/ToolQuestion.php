<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine ORM entity for the tool_question table.
 *
 * Stores pending tool-local questions (e.g. bash background prompts) so
 * they can be surfaced to the TUI overlay and answered even across process
 * boundaries (tool messenger worker → DB → controller → TUI → controller
 * → tool messenger worker).
 *
 * Lifecycle:
 *   Create with status=Pending.
 *   Mark emitted (setEmittedAt) after the first runtime event is sent.
 *   Mark answered (setAnswer) when a response comes back.
 *   Mark cancelled when the tool detects cancellation while waiting.
 *
 * created_at / updated_at are maintained by TimestampableLifecycleTrait.
 * emitted_at and answered_at are set explicitly.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tool_question')]
#[ORM\HasLifecycleCallbacks]
class ToolQuestion
{
    use TimestampableLifecycleTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;

    /** Unique request ID (e.g. 'bash_bg_run123_tc456_pid789'). */
    #[ORM\Column(name: 'request_id', type: 'string', unique: true)]
    public string $requestId = '';

    /** The run/session this question belongs to. */
    #[ORM\Column(name: 'run_id', type: 'string')]
    public string $runId = '';

    /** The tool call ID within the run. */
    #[ORM\Column(name: 'tool_call_id', type: 'string')]
    public string $toolCallId = '';

    /** The tool name (e.g. 'bash'). */
    #[ORM\Column(name: 'tool_name', type: 'string')]
    public string $toolName = '';

    /** Process PID. */
    #[ORM\Column(type: 'integer')]
    public int $pid = 0;

    /** Path to the process log file. */
    #[ORM\Column(name: 'log_path', type: 'string')]
    public string $logPath = '';

    /** Capped command preview (max 200 chars). */
    #[ORM\Column(name: 'command_preview', type: 'string', length: 200)]
    public string $commandPreview = '';

    /** Human-readable prompt text for the TUI question overlay. */
    #[ORM\Column(type: 'string')]
    public string $prompt = '';

    /** Question kind (e.g. 'confirm'). */
    #[ORM\Column(type: 'string', length: 50)]
    public string $kind = 'confirm';

    /** Lifecycle status. */
    #[ORM\Column(name: 'status', type: 'string', enumType: ToolQuestionStatusEnum::class)]
    public ToolQuestionStatusEnum $status = ToolQuestionStatusEnum::Pending;

    /** The boolean answer, set when status becomes Answered. */
    #[ORM\Column(type: 'boolean', nullable: true)]
    public ?bool $answer = null;

    /** When the question was first emitted as a runtime event. Null before first emission. */
    #[ORM\Column(name: 'emitted_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $emittedAt = null;

    /** When the question was answered or cancelled. */
    #[ORM\Column(name: 'answered_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $answeredAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    /** No-arg constructor for Doctrine hydration. Sets timestamp defaults. */
    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Factory method to create a pending tool question.
     */
    public static function create(
        string $requestId,
        string $runId,
        string $toolCallId,
        string $toolName,
        int $pid,
        string $logPath,
        string $commandPreview,
        string $prompt,
        string $kind = 'confirm',
    ): self {
        $entity = new self();
        $entity->requestId = $requestId;
        $entity->runId = $runId;
        $entity->toolCallId = $toolCallId;
        $entity->toolName = $toolName;
        $entity->pid = $pid;
        $entity->logPath = $logPath;
        $entity->commandPreview = $commandPreview;
        $entity->prompt = $prompt;
        $entity->kind = $kind;

        return $entity;
    }

    /** Mark the question as emitted to the runtime (avoids duplicate events). */
    public function markEmitted(): void
    {
        $this->emittedAt = new \DateTimeImmutable();
    }

    /** Set the boolean answer. */
    public function setAnswer(bool $answer): void
    {
        $this->answer = $answer;
        $this->status = ToolQuestionStatusEnum::Answered;
        $this->answeredAt = new \DateTimeImmutable();
    }

    /** Mark the question as cancelled (user/tool cancelled without answering). */
    public function markCancelled(): void
    {
        $this->status = ToolQuestionStatusEnum::Cancelled;
        $this->answeredAt = new \DateTimeImmutable();
    }

    /** True if the question has been answered or cancelled. */
    public function isResolved(): bool
    {
        return ToolQuestionStatusEnum::Pending !== $this->status;
    }
}
