<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/** Native facts for an accepted background job, carried on its ordinary result. */
final readonly class BashNativeCompletionMetadataDTO
{
    public function __construct(
        #[SerializedName('run_id')]
        #[Assert\NotBlank]
        public string $runId,
        #[SerializedName('tool_call_id')]
        #[Assert\NotBlank]
        public string $toolCallId,
        #[Assert\NotBlank]
        public string $command,
        #[SerializedName('log_path')]
        #[Assert\NotBlank]
        public string $logPath,
        #[SerializedName('status_path')]
        #[Assert\NotBlank]
        public string $statusPath,
        #[Assert\Positive]
        public int $pid,
        #[SerializedName('started_at')]
        public \DateTimeImmutable $startedAt,
        #[SerializedName('accepted_at')]
        public \DateTimeImmutable $acceptedAt,
        #[Assert\Positive]
        public ?int $pgid = null,
        #[SerializedName('deadline_nanoseconds')]
        public ?int $deadlineNanoseconds = null,
    ) {
    }
}
