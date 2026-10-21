<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RecordBackgroundCompletionDTO
{
    public function __construct(#[Assert\Valid] public BackgroundCompletionReceiptDTO $receipt)
    {
    }
}
