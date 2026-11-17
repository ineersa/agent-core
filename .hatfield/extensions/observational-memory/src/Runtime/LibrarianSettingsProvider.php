<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class LibrarianSettingsProvider
{
    public static function fromArray(mixed $raw): LibrarianSettingsDTO
    {
        $value = (new Processor())->processConfiguration(new LibrarianConfiguration(), [$raw ?? []]);
        $token = $value['token'] ?? null;
        $token = '' === $token ? null : $token;
        // Match Hatfield secret settings: env:NAME is resolved only at the runtime boundary.
        if (null !== $token && str_starts_with($token, 'env:')) {
            $resolved = getenv(substr($token, 4));
            if (false === $resolved || '' === $resolved) {
                throw new InvalidConfigurationException('Librarian token environment reference could not be resolved.');
            }
            $token = $resolved;
        }
        if (null !== $token && 1 === preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new InvalidConfigurationException('Librarian token must not contain whitespace or control characters.');
        }

        return new LibrarianSettingsDTO($value['url'], $value['timeout_seconds'], $token);
    }
}
