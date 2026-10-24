<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates {@see ExactProviderModelId}.
 */
final class ExactProviderModelIdValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ExactProviderModelId) {
            throw new UnexpectedTypeException($constraint, ExactProviderModelId::class);
        }

        if (!\is_string($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }

        $trimmed = trim($value);
        if ('' === $trimmed || str_starts_with($trimmed, '@') || !str_contains($trimmed, '/')) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
