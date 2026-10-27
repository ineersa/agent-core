<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;

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

    #[ORM\Column(name: 'deferred_id', length: 36, nullable: true)]
    public ?string $deferredId = null;

    #[ORM\Column(name: 'invocation_reference_json', type: Types::TEXT, nullable: true)]
    public ?string $invocationReferenceJson = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $origin = [];
}
