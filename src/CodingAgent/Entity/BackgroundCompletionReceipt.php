<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionOriginEnum;

/** Retained admission and forwarding evidence, independent of the pending mailbox. */
#[ORM\Entity(repositoryClass: BackgroundCompletionReceiptRepository::class)]
#[ORM\Table(name: 'background_completion_receipt')]
#[ORM\Index(name: 'background_completion_forwarding', columns: ['root_run_id', 'disposition'])]
final class BackgroundCompletionReceipt
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    public string $identity;

    #[ORM\Column(name: 'source_run_id', length: 255)]
    public string $sourceRunId;

    #[ORM\Column(name: 'accepted_destination_run_id', length: 255)]
    public string $acceptedDestinationRunId;

    #[ORM\Column(name: 'destination_run_id', length: 255)]
    public string $destinationRunId;

    #[ORM\Column(name: 'root_run_id', length: 255)]
    public string $rootRunId;

    #[ORM\Column(type: Types::TEXT)]
    public string $text;

    #[ORM\Column(name: 'forwarded_text', type: Types::TEXT)]
    public string $forwardedText;

    #[ORM\Column(length: 32, enumType: BackgroundCompletionDispositionEnum::class)]
    public BackgroundCompletionDispositionEnum $disposition;

    #[ORM\Column(length: 32, enumType: BackgroundCompletionOriginEnum::class)]
    public BackgroundCompletionOriginEnum $origin;

    #[ORM\Column(name: 'deferred_id', length: 36, nullable: true)]
    public ?string $deferredId = null;

    #[ORM\Column(name: 'source_tool_call_id', length: 255, nullable: true)]
    public ?string $sourceToolCallId = null;

    #[ORM\Column(name: 'source_turn_no', nullable: true)]
    public ?int $sourceTurnNo = null;

    #[ORM\Column(name: 'source_step_id', length: 255, nullable: true)]
    public ?string $sourceStepId = null;

    #[ORM\Column(name: 'source_attempt', nullable: true)]
    public ?int $sourceAttempt = null;

    #[ORM\Column(name: 'source_idempotency_key', length: 255, nullable: true)]
    public ?string $sourceIdempotencyKey = null;
}
