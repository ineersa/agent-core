<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\Exception\AiConfigurationException;

/** Resolves literal secrets or env:NAME references without trimming their values. */
final class SecretResolver
{
    public static function resolve(?string $value): ?string
    {
        if (null === $value || !str_starts_with($value, 'env:')) {
            return $value;
        }

        $name = substr($value, 4);
        if ('' === $name) {
            throw new AiConfigurationException('The env: syntax must specify a variable name, got bare "env:".');
        }

        $resolved = getenv($name);

        return false === $resolved || '' === $resolved ? null : $resolved;
    }
}
