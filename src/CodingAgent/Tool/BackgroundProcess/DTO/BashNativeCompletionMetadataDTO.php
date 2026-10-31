<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Private native Bash facts carried inside ordinary completion details.
 *
 * ToolPayloadMiddleware validates them into BashNativeCompletionStamp, seals
 * visible content without this key, and applies the stamp on owner receive
 * before ordinary admission.
 */
final readonly class BashNativeCompletionMetadataDTO
{
    public function __construct(
        #[SerializedName('record_id')]
        #[Assert\Positive]
        public int $recordId,
        #[SerializedName('run_id')]
        #[Assert\NotBlank]
        public string $runId,
        #[SerializedName('tool_call_id')]
        #[Assert\NotBlank]
        public string $toolCallId,
        #[Assert\Positive]
        public ?int $pid = null,
        public ?int $pgid = null,
        #[SerializedName('started_at')]
        public ?\DateTimeImmutable $startedAt = null,
        #[SerializedName('deadline_nanoseconds')]
        public ?int $deadlineNanoseconds = null,
        #[SerializedName('accepted_at')]
        public ?\DateTimeImmutable $acceptedAt = null,
        #[SerializedName('finished_at')]
        public ?\DateTimeImmutable $finishedAt = null,
        #[SerializedName('exit_code')]
        public ?int $exitCode = null,
        public ?BackgroundProcessStatusEnum $status = null,
        #[SerializedName('stopped_by_user')]
        public bool $stoppedByUser = false,
    ) {
    }
}
