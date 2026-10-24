<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates {@see OpenUnitRatio} without clamping malformed input.
 */
final class OpenUnitRatioValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof OpenUnitRatio) {
            throw new UnexpectedTypeException($constraint, OpenUnitRatio::class);
        }

        if (!is_numeric($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }

        $ratio = (float) $value;
        if ($ratio <= 0.0 || $ratio >= 1.0) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
