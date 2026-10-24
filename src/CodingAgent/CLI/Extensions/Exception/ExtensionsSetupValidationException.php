<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Exception;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Typed refusal for extensions:setup form validation failures.
 *
 * Constraint violations stay on the ValidationFailedException cause, matching
 * other CodingAgent typed validation refusals.
 */
final class ExtensionsSetupValidationException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        ConstraintViolationListInterface $violations,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous ?? new ValidationFailedException(null, $violations));
    }

    public static function forMessage(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, new ConstraintViolationList(), $previous);
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
