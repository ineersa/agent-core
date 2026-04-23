<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ReplayEventsQueryRequest
{
    public function __construct(
        #[Assert\PositiveOrZero(message: 'Field "last_event_id" must be a non-negative integer.')]
        public int $last_event_id = 0,
    ) {
    }
}
