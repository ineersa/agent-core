<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion\DTO;

use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BackgroundCompletionReceiptDTO
{
    /** @param array<string, mixed> $origin Host-recorded producer identities, retained with the first admission. */
    public function __construct(
        #[Assert\NotBlank] public string $identity,
        #[Assert\NotBlank] public string $sourceRunId,
        #[Assert\NotBlank] public string $acceptedDestinationRunId,
        #[Assert\NotBlank] public string $destinationRunId,
        #[Assert\NotBlank] public string $rootRunId,
        public string $text,
        public string $forwardedText,
        public BackgroundCompletionDispositionEnum $disposition,
        #[Assert\NotBlank(allowNull: true)] public ?string $deferredId = null,
        public ?ToolPayloadReferenceDTO $invocation = null,
        public array $origin = [],
    ) {
    }
}
