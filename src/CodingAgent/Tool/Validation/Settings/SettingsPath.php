<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Validation\Settings;

use Symfony\Component\Validator\Constraint;

/**
 * Property constraint: settings `path` must be a non-empty dotted path that
 * {@see \Ineersa\CodingAgent\Config\SettingsValueResolver::propertyPath()} accepts.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class SettingsPath extends Constraint
{
    public string $message = 'The "path" argument must be a non-empty dotted settings path. Example: tui.theme';

    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
        if (null !== $message) {
            $this->message = $message;
        }
    }
}
