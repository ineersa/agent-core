<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class FiniteNumberValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof FiniteNumber) {
            throw new UnexpectedTypeException($constraint, FiniteNumber::class);
        }

        if (null === $value) {
            return;
        }

        if ((!(\is_int($value) || \is_float($value))) || !is_finite((float) $value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
