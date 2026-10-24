<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Typed refusal for extensions:setup form validation failures.
 */
final class ExtensionsSetupValidationException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly ConstraintViolationListInterface $violations,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous ?? new ValidationFailedException(null, $violations));
    }

    public static function fromViolations(ConstraintViolationListInterface $violations): self
    {
        $parts = [];
        foreach ($violations as $violation) {
            $path = $violation->getPropertyPath();
            $parts[] = ('' === $path ? '' : $path.': ').$violation->getMessage();
        }

        return new self(
            [] === $parts ? 'extensions:setup form failed validation.' : implode(' ', $parts),
            $violations,
        );
    }
}
