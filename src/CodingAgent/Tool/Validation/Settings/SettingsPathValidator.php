<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Validation\Settings;

use Ineersa\CodingAgent\Config\SettingsValueResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates a settings dotted path against {@see SettingsValueResolver::propertyPath()}.
 */
final class SettingsPathValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SettingsPath) {
            throw new UnexpectedTypeException($constraint, SettingsPath::class);
        }

        if (!\is_string($value)) {
            return;
        }

        $trimmed = trim($value);
        if ('' === $trimmed || null === SettingsValueResolver::propertyPath($trimmed)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->addViolation();
        }
    }
}
