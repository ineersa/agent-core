<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates {@see AbsolutePathList}.
 */
final class AbsolutePathListValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AbsolutePathList) {
            throw new UnexpectedTypeException($constraint, AbsolutePathList::class);
        }

        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            if (!\is_string($item) || '' === trim($item) || !str_starts_with($item, '/')) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ value }}', \is_scalar($item) ? (string) $item : get_debug_type($item))
                    ->addViolation();

                return;
            }
        }
    }
}
