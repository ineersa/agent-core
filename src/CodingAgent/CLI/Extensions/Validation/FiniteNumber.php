<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * Accepts null or a finite int/float. Rejects INF/NAN.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class FiniteNumber extends Constraint
{
    public string $message = 'This value must be a finite number.';

    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
        if (null !== $message) {
            $this->message = $message;
        }
    }
}
