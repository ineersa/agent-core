<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TranscriptPageQueryRequest
{
    public function __construct(
        #[Assert\PositiveOrZero(message: 'Field "cursor" must be a non-negative integer.')]
        public int $cursor = 0,
        #[Assert\PositiveOrZero(message: 'Field "limit" must be a non-negative integer.')]
        public int $limit = 50,
    ) {
    }
}
